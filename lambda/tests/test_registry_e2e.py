"""
End-to-end tests for the registry system.

Tests the complete workflow across both systems:
  - WordPress REST API  (registry CPT + user management)
  - restart-lambda API  (SQLite items via FastAPI)

Run with:
    WP_LOCAL_URL=http://localhost:8082 \
    WP_LOCAL_USER=<your local wp user> \
    WP_LOCAL_APP_PWD="<your app password from your local wp install.>" \
    pytest tests/test_registry_e2e.py -v

Tests are numbered and stateful — they run in definition order and build on
shared state stored in `_state`. All WP resources created during the run are
cleaned up on teardown even if individual tests fail.
"""

import os

import pytest
from fastapi.testclient import TestClient
from wp_python import ApplicationPasswordAuth, WordPressClient

from app.models.registry import RegistryMeta

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

WP_URL = os.environ.get("WP_LOCAL_URL", "http://localhost:8082")
WP_USER = os.environ.get("WP_LOCAL_USER", "andrew")
WP_APP_PWD = os.environ.get("WP_LOCAL_APP_PWD", "ddjo 65OK daxH MDe1 Nn8L asVu")

NUM_USERS = 12
ITEMS_PER_REGISTRY = 3

# Shared state populated and consumed across tests
_state: dict = {}


# ---------------------------------------------------------------------------
# Skip guard
# ---------------------------------------------------------------------------


def _wp_available() -> bool:
    try:
        import httpx

        r = httpx.get(f"{WP_URL}/?rest_route=/wp/v2/types/restart-registry", timeout=3)
        return r.status_code == 200
    except Exception:
        return False


wp_required = pytest.mark.skipif(
    not _wp_available(),
    reason="Local WordPress not reachable",
)


# ---------------------------------------------------------------------------
# Module-scoped fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(scope="module")
def wp():
    auth = ApplicationPasswordAuth(WP_USER, WP_APP_PWD)
    client = WordPressClient(WP_URL, auth=auth)
    yield client
    client.close()


@pytest.fixture(scope="module")
def lambda_client():
    from app.auth.wp_client import clear_cache
    from app.database import close_db, init_db
    from app.main import app

    auth = ApplicationPasswordAuth(WP_USER, WP_APP_PWD)
    clear_cache()
    init_db()
    with TestClient(app, headers=auth.get_headers()) as client:
        yield client
    clear_cache()
    close_db()


@pytest.fixture(scope="module")
def e2e_state(wp):
    """Shared mutable bag for all tests. Deletes all WP resources on teardown."""
    state: dict = {
        "users": [],  # list of WP user dicts {id, username}
        "registry_ids": [],  # WP post IDs, for cleanup
        "registries": [],  # list of full registry dicts built up across tests
    }
    yield state

    # ---- teardown --------------------------------------------------------
    cpt = wp.custom_post_type("restart-registry")
    # wp.users.me() fails when WP returns [] for meta; use raw request instead
    admin_id = wp._request("GET", "/wp/v2/users/me")["id"]

    for post_id in reversed(state["registry_ids"]):
        try:
            cpt.delete(post_id, force=True)
        except Exception:
            pass

    for user in reversed(state["users"]):
        try:
            wp.users.delete(user["id"], force=True, reassign=admin_id)
        except Exception:
            pass


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _make_item_payload(i: int, registry_id: int, quantity_needed: int = 1) -> dict:
    return {
        "registry_id": registry_id,
        "name": f"E2E Item {i}",
        "url": f"https://example.com/product/e2e-item-{i}",
        "price": round(10.0 + i * 5, 2),
        "quantity_needed": quantity_needed,
    }


def _meta_from_wp(post: dict) -> RegistryMeta:
    return RegistryMeta.from_wp_meta(post.get("meta", {}))


# ---------------------------------------------------------------------------
# E2E test class  (methods run in definition order)
# ---------------------------------------------------------------------------


@wp_required
class TestRegistryE2E:
    # ------------------------------------------------------------------
    # 01 — Create 12 WordPress test users
    # ------------------------------------------------------------------

    def test_01_create_wp_users(self, wp, e2e_state):
        # Clean up any leftover users from a previous failed run
        admin_id = wp._request("GET", "/wp/v2/users/me")["id"]
        existing = wp._request(
            "GET", "/wp/v2/users", params={"search": "rr_e2e_", "per_page": 100}
        )
        for u in existing if isinstance(existing, list) else []:
            if u.get("slug", "").startswith("rr_e2e_"):
                try:
                    wp.users.delete(u["id"], force=True, reassign=admin_id)
                except Exception:
                    pass

        for i in range(1, NUM_USERS + 1):
            user = wp.users.create(
                {
                    "username": f"rr_e2e_{i:02d}",
                    "email": f"rr_e2e_{i:02d}@e2e.local",
                    "password": "E2ePassword123!",
                    "roles": ["registry_user"],
                }
            )
            e2e_state["users"].append({"id": user.id, "username": user.slug})

        assert len(e2e_state["users"]) == NUM_USERS

    # ------------------------------------------------------------------
    # 02 — Create one WP registry post per user
    # ------------------------------------------------------------------

    def test_02_create_registries(self, wp, e2e_state):
        cpt = wp.custom_post_type("restart-registry")

        for u in e2e_state["users"]:
            meta = RegistryMeta(event_type="e2e-test")
            post = cpt.create(
                {
                    "title": f"Registry of {u['username']}",
                    "status": "publish",
                    "author": u["id"],
                    "meta": meta.to_wp_meta(),
                }
            )
            e2e_state["registry_ids"].append(post["id"])
            e2e_state["registries"].append(
                {
                    "post_id": post["id"],
                    "user": u,
                    "item_ids": [],
                }
            )

        assert len(e2e_state["registries"]) == NUM_USERS

        # Verify each registry is readable from WP
        for reg in e2e_state["registries"]:
            fetched = cpt.get(reg["post_id"])
            assert fetched["id"] == reg["post_id"]
            m = _meta_from_wp(fetched)
            assert m.event_type == "e2e-test"

    # ------------------------------------------------------------------
    # 03 — Add items to each registry via Lambda; link via WP meta
    # ------------------------------------------------------------------

    def test_03_add_items_to_registries(self, wp, lambda_client, e2e_state):
        cpt = wp.custom_post_type("restart-registry")
        item_counter = 0

        for reg in e2e_state["registries"]:
            created_ids = []
            for j in range(ITEMS_PER_REGISTRY):
                item_counter += 1
                payload = _make_item_payload(item_counter, registry_id=reg["post_id"], quantity_needed=2)
                resp = lambda_client.post("/items", json=payload)
                assert resp.status_code == 201, resp.text
                item_id = resp.json()["data"]["id"]
                created_ids.append(item_id)

            # Link items in WP meta
            meta = _meta_from_wp(cpt.get(reg["post_id"]))
            meta.item_ids = created_ids
            cpt.update(reg["post_id"], {"meta": meta.to_wp_meta()})
            reg["item_ids"] = created_ids

        # Verify every registry has items in both systems
        total_items = NUM_USERS * ITEMS_PER_REGISTRY
        all_item_ids = [
            iid for reg in e2e_state["registries"] for iid in reg["item_ids"]
        ]
        assert len(all_item_ids) == total_items
        assert len(set(all_item_ids)) == total_items  # all unique

        for reg in e2e_state["registries"]:
            fetched_meta = _meta_from_wp(cpt.get(reg["post_id"]))
            assert fetched_meta.item_ids == reg["item_ids"]

    # ------------------------------------------------------------------
    # 04 — Invite internal users (WP usernames) to each registry
    # ------------------------------------------------------------------

    def test_04_invite_internal_users(self, wp, e2e_state):
        cpt = wp.custom_post_type("restart-registry")
        users = e2e_state["users"]

        for idx, reg in enumerate(e2e_state["registries"]):
            # Invite the two neighbours (wrap around)
            invitees = [
                users[(idx + 1) % NUM_USERS]["username"],
                users[(idx + 2) % NUM_USERS]["username"],
            ]
            meta = _meta_from_wp(cpt.get(reg["post_id"]))
            meta.invitees = invitees
            cpt.update(reg["post_id"], {"meta": meta.to_wp_meta()})
            reg["invitees"] = invitees

        # Verify
        for reg in e2e_state["registries"]:
            m = _meta_from_wp(cpt.get(reg["post_id"]))
            assert m.invitees == reg["invitees"]
            assert all(u.startswith("rr_e2e_") for u in m.invitees)

    # ------------------------------------------------------------------
    # 05 — Invite external users (email addresses) to each registry
    # ------------------------------------------------------------------

    def test_05_invite_external_users(self, wp, e2e_state):
        cpt = wp.custom_post_type("restart-registry")

        for idx, reg in enumerate(e2e_state["registries"]):
            external = [
                f"friend_a_{idx}@external.com",
                f"friend_b_{idx}@external.com",
            ]
            meta = _meta_from_wp(cpt.get(reg["post_id"]))
            meta.invitees = meta.invitees + external
            cpt.update(reg["post_id"], {"meta": meta.to_wp_meta()})
            reg["invitees"] = meta.invitees

        # Verify: each registry has 2 internal + 2 external invitees
        for reg in e2e_state["registries"]:
            m = _meta_from_wp(cpt.get(reg["post_id"]))
            assert len(m.invitees) == 4
            wp_users = [i for i in m.invitees if "@" not in i]
            emails = [i for i in m.invitees if "@" in i]
            assert len(wp_users) == 2
            assert len(emails) == 2

    # ------------------------------------------------------------------
    # 06 — Increment quantity_purchased on all items (simulate purchases)
    # ------------------------------------------------------------------

    def test_06_increment_item_quantities(self, lambda_client, e2e_state):
        for reg in e2e_state["registries"]:
            for item_id in reg["item_ids"]:
                # Get current quantity
                get_resp = lambda_client.get(f"/items/{item_id}")
                assert get_resp.status_code == 200
                current = get_resp.json()["data"]["quantity_purchased"]

                # Increment by 1
                put_resp = lambda_client.put(
                    f"/items/{item_id}",
                    json={"quantity_purchased": current + 1},
                )
                assert put_resp.status_code == 200
                assert put_resp.json()["data"]["quantity_purchased"] == current + 1

        # Verify all items show quantity_purchased == 1
        for reg in e2e_state["registries"]:
            for item_id in reg["item_ids"]:
                data = lambda_client.get(f"/items/{item_id}").json()["data"]
                assert data["quantity_purchased"] == 1

    # ------------------------------------------------------------------
    # 07 — Decrement quantity_purchased; floor at 0
    # ------------------------------------------------------------------

    def test_07_decrement_item_quantities(self, lambda_client, e2e_state):
        # Decrement first item of every other registry back to 0
        for idx, reg in enumerate(e2e_state["registries"]):
            if idx % 2 == 0:
                item_id = reg["item_ids"][0]
                get_resp = lambda_client.get(f"/items/{item_id}")
                current = get_resp.json()["data"]["quantity_purchased"]
                new_val = max(0, current - 1)

                put_resp = lambda_client.put(
                    f"/items/{item_id}",
                    json={"quantity_purchased": new_val},
                )
                assert put_resp.status_code == 200
                assert put_resp.json()["data"]["quantity_purchased"] == new_val

        # Verify floor: quantity_purchased cannot be set below 0
        any_item_id = e2e_state["registries"][0]["item_ids"][0]
        bad_resp = lambda_client.put(
            f"/items/{any_item_id}",
            json={"quantity_purchased": -1},
        )
        assert bad_resp.status_code == 422

    # ------------------------------------------------------------------
    # 08 — Toggle registry visibility (private / public)
    # ------------------------------------------------------------------

    def test_08_toggle_registry_privacy(self, wp, e2e_state):
        cpt = wp.custom_post_type("restart-registry")

        # Set even-indexed registries to private
        for idx, reg in enumerate(e2e_state["registries"]):
            status = "private" if idx % 2 == 0 else "publish"
            cpt.update(reg["post_id"], {"status": status})
            reg["status"] = status

        # Verify
        for reg in e2e_state["registries"]:
            fetched = cpt.get(reg["post_id"])
            assert fetched["status"] == reg["status"]

        private_count = sum(
            1 for r in e2e_state["registries"] if r["status"] == "private"
        )
        public_count = sum(
            1 for r in e2e_state["registries"] if r["status"] == "publish"
        )
        assert private_count == NUM_USERS // 2
        assert public_count == NUM_USERS // 2

    # ------------------------------------------------------------------
    # 09 — Cross-system consistency check
    # ------------------------------------------------------------------

    def test_09_cross_system_consistency(self, wp, lambda_client, e2e_state):
        """
        For every registry: the item_ids stored in WP meta must exist in
        Lambda's SQLite, and item quantities must be readable from Lambda.
        """
        cpt = wp.custom_post_type("restart-registry")

        for reg in e2e_state["registries"]:
            # Fetch WP meta
            wp_meta = _meta_from_wp(cpt.get(reg["post_id"]))

            # Every item_id in WP meta must exist in Lambda
            assert set(wp_meta.item_ids) == set(reg["item_ids"])
            for item_id in wp_meta.item_ids:
                resp = lambda_client.get(f"/items/{item_id}")
                assert resp.status_code == 200, f"Item {item_id} missing from Lambda"
                item = resp.json()["data"]
                assert item["quantity_needed"] == 2
                assert item["quantity_purchased"] >= 0
                assert item["registry_id"] == reg["post_id"], (
                    f"Item {item_id} has registry_id={item['registry_id']}, "
                    f"expected {reg['post_id']}"
                )

            # WP author matches the registry's owner
            fetched = cpt.get(reg["post_id"])
            assert fetched["author"] == reg["user"]["id"]

    # ------------------------------------------------------------------
    # 10 — Full lifecycle: single registry from scratch to deletion
    # ------------------------------------------------------------------

    def test_10_full_registry_lifecycle(self, wp, lambda_client, e2e_state):
        """
        Complete workflow for one registry:
          create user → create registry → add items → invite users →
          purchase items → verify → make private → delete
        """
        cpt = wp.custom_post_type("restart-registry")
        admin_id = wp._request("GET", "/wp/v2/users/me")["id"]

        # 1. Create a dedicated user
        user = wp.users.create(
            {
                "username": "rr_lifecycle_user",
                "email": "rr_lifecycle@e2e.local",
                "password": "Lifecycle123!",
                "roles": ["registry_user"],
            }
        )
        e2e_state["users"].append({"id": user.id, "username": user.slug})

        # 2. Create registry in WP
        meta = RegistryMeta(
            event_type="wedding",
            event_date="2026-09-20",
        )
        post = cpt.create(
            {
                "title": "Lifecycle Wedding Registry",
                "status": "publish",
                "author": user.id,
                "meta": meta.to_wp_meta(),
            }
        )
        e2e_state["registry_ids"].append(post["id"])

        # 3. Create 5 items in Lambda
        item_ids = []
        for i in range(1, 6):
            resp = lambda_client.post(
                "/items",
                json={
                    "registry_id": post["id"],
                    "name": f"Wedding Gift {i}",
                    "url": f"https://example.com/gift/{i}",
                    "price": float(i * 25),
                    "quantity_needed": i,
                },
            )
            assert resp.status_code == 201
            item_ids.append(resp.json()["data"]["id"])

        # 4. Link items to registry via WP meta
        meta.item_ids = item_ids
        meta.invitees = [
            e2e_state["users"][0]["username"],  # internal
            "maid_of_honor@wedding.com",  # external
        ]
        cpt.update(post["id"], {"meta": meta.to_wp_meta()})

        # 5. Purchase 3 of the 5 items
        for item_id in item_ids[:3]:
            resp = lambda_client.put(
                f"/items/{item_id}",
                json={"quantity_purchased": 1},
            )
            assert resp.status_code == 200
            assert resp.json()["data"]["quantity_purchased"] == 1

        # 6. Verify full state
        fetched = cpt.get(post["id"])
        final_meta = _meta_from_wp(fetched)

        assert set(final_meta.item_ids) == set(item_ids)
        assert final_meta.event_type == "wedding"
        assert final_meta.event_date == "2026-09-20"
        assert len(final_meta.invitees) == 2

        purchased_items = [
            lambda_client.get(f"/items/{iid}").json()["data"] for iid in item_ids[:3]
        ]
        unpurchased_items = [
            lambda_client.get(f"/items/{iid}").json()["data"] for iid in item_ids[3:]
        ]
        assert all(i["quantity_purchased"] == 1 for i in purchased_items)
        assert all(i["quantity_purchased"] == 0 for i in unpurchased_items)

        # 7. Make private
        cpt.update(post["id"], {"status": "private"})
        assert cpt.get(post["id"])["status"] == "private"

        # 8. Delete items from Lambda, then registry from WP
        for item_id in item_ids:
            resp = lambda_client.delete(f"/items/{item_id}")
            assert resp.status_code == 200

        cpt.delete(post["id"], force=True)
        e2e_state["registry_ids"].remove(post["id"])

        # Verify items are gone from Lambda
        for item_id in item_ids:
            assert lambda_client.get(f"/items/{item_id}").status_code == 404

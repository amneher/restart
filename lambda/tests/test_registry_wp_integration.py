"""
Integration tests for registry storage in WordPress metadata.

Requires a local WordPress instance running at WP_LOCAL_URL with
application-password credentials in the environment.

Run with:
    WP_LOCAL_URL=http://localhost:8082 \
    WP_LOCAL_USER=andrew \
    WP_LOCAL_APP_PWD="ddjo 65OK daxH MDe1 Nn8L asVu" \
    pytest tests/test_registry_wp_integration.py -v
"""

import os
import pytest
from wp_python import WordPressClient, ApplicationPasswordAuth

from app.models.registry import RegistryMeta

# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

WP_URL = os.environ.get("WP_LOCAL_URL", "http://localhost:8082")
WP_USER = os.environ.get("WP_LOCAL_USER", "andrew")
WP_APP_PWD = os.environ.get("WP_LOCAL_APP_PWD", "ddjo 65OK daxH MDe1 Nn8L asVu")
REST_PATH = "/wp/v2/restart-registry"


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


@pytest.fixture(scope="module")
def wp():
    auth = ApplicationPasswordAuth(WP_USER, WP_APP_PWD)
    client = WordPressClient(WP_URL, auth=auth)
    yield client
    client.close()


@pytest.fixture
def registry_post(wp):
    """Create a registry post and delete it after the test."""
    post = wp.custom_post_type("restart-registry").create({
        "title": "Test Registry",
        "status": "publish",
    })
    yield post
    try:
        wp.custom_post_type("restart-registry").delete(post["id"], force=True)
    except Exception:
        pass


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

@wp_required
class TestRegistryMetaRoundTrip:
    def test_create_registry_post(self, wp):
        cpt = wp.custom_post_type("restart-registry")
        post = cpt.create({"title": "My Wedding Registry", "status": "publish"})
        try:
            assert post["id"] > 0
            assert post["type"] == "restart-registry"
        finally:
            cpt.delete(post["id"], force=True)

    def test_write_and_read_meta(self, wp, registry_post):
        meta = RegistryMeta(
            invitees=["alice", "bob"],
            item_ids=[10, 20, 30],
            event_type="wedding",
            event_date="2026-06-15",
        )
        cpt = wp.custom_post_type("restart-registry")
        cpt.update(registry_post["id"], {"meta": meta.to_wp_meta()})

        fetched = cpt.get(registry_post["id"])
        restored = RegistryMeta.from_wp_meta(fetched["meta"])

        assert restored.invitees == ["alice", "bob"]
        assert restored.item_ids == [10, 20, 30]
        assert restored.event_type == "wedding"
        assert restored.event_date == "2026-06-15"

    def test_empty_meta_defaults(self, wp, registry_post):
        cpt = wp.custom_post_type("restart-registry")
        fetched = cpt.get(registry_post["id"])
        meta = RegistryMeta.from_wp_meta(fetched.get("meta", {}))

        assert meta.invitees == []
        assert meta.item_ids == []
        assert meta.event_type is None
        assert meta.event_date is None

    def test_update_invitees(self, wp, registry_post):
        cpt = wp.custom_post_type("restart-registry")
        meta = RegistryMeta(invitees=["carol"])
        cpt.update(registry_post["id"], {"meta": meta.to_wp_meta()})

        meta2 = RegistryMeta(invitees=["carol", "dave"])
        cpt.update(registry_post["id"], {"meta": meta2.to_wp_meta()})

        fetched = cpt.get(registry_post["id"])
        restored = RegistryMeta.from_wp_meta(fetched["meta"])
        assert restored.invitees == ["carol", "dave"]

    def test_private_registry_status(self, wp, registry_post):
        cpt = wp.custom_post_type("restart-registry")
        cpt.update(registry_post["id"], {"status": "private"})

        fetched = cpt.get(registry_post["id"])
        assert fetched["status"] == "private"

    def test_publish_registry_status(self, wp, registry_post):
        cpt = wp.custom_post_type("restart-registry")
        cpt.update(registry_post["id"], {"status": "private"})
        cpt.update(registry_post["id"], {"status": "publish"})

        fetched = cpt.get(registry_post["id"])
        assert fetched["status"] == "publish"

    def test_list_registries(self, wp, registry_post):
        cpt = wp.custom_post_type("restart-registry")
        posts = cpt.list(per_page=100)
        ids = [p["id"] for p in posts]
        assert registry_post["id"] in ids

    def test_delete_registry(self, wp):
        cpt = wp.custom_post_type("restart-registry")
        post = cpt.create({"title": "To Delete", "status": "publish"})
        post_id = post["id"]

        cpt.delete(post_id, force=True)

        posts = cpt.list(per_page=100)
        assert post_id not in [p["id"] for p in posts]
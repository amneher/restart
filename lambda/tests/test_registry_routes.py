"""Unit tests for registry CRUD routes (WordPress mocked via wp_python)."""

import base64
import os

os.environ.setdefault("DATABASE_PATH", ":memory:")

import pytest
from unittest.mock import MagicMock, patch
from fastapi.testclient import TestClient

from app.auth.models import WPUser
from app.main import app
from app.database import init_db, close_db


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _basic_auth(user: str = "testuser", pwd: str = "xxxx") -> dict:
    creds = base64.b64encode(f"{user}:{pwd}".encode()).decode()
    return {"Authorization": f"Basic {creds}"}


def _wp_user(id: int = 1, username: str = "testuser", roles=None) -> WPUser:
    return WPUser(
        id=id,
        username=username,
        email=f"{username}@example.com",
        display_name=username.title(),
        roles=roles or ["subscriber"],
        capabilities={"read": True},
    )


def _wp_post(
    id: int = 100,
    author: int = 1,
    title: str = "Test Registry",
    status: str = "publish",
    meta: dict | None = None,
) -> dict:
    return {
        "id": id,
        "author": author,
        "title": {"rendered": title},
        "status": status,
        "date": "2026-04-01T10:00:00",
        "modified": "2026-04-01T12:00:00",
        "meta": meta or {},
    }


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture(autouse=True)
def _db():
    init_db()
    yield
    close_db()


@pytest.fixture
def client():
    return TestClient(app)


@pytest.fixture
def mock_auth():
    """Patch validate_credentials to return a test user."""
    with patch("app.auth.dependencies.validate_credentials") as m:
        m.return_value = _wp_user()
        yield m


@pytest.fixture
def mock_wp():
    """Patch get_wp_client to return a mock WordPressClient."""
    with patch("app.routes.registry.get_wp_client") as m:
        mock_client = MagicMock()
        mock_client.__enter__.return_value = mock_client
        m.return_value = mock_client
        yield mock_client


# ---------------------------------------------------------------------------
# CREATE
# ---------------------------------------------------------------------------

class TestCreateRegistry:
    def test_create_success(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.create.return_value = _wp_post(
            id=200, author=1, title="Wedding Registry"
        )
        resp = client.post(
            "/registries",
            json={"title": "Wedding Registry", "username": "testuser"},
            headers=_basic_auth(),
        )
        assert resp.status_code == 201
        data = resp.json()
        assert data["id"] == 200
        assert data["title"] == "Wedding Registry"

    def test_create_private(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.create.return_value = _wp_post(
            id=201, status="private"
        )
        resp = client.post(
            "/registries",
            json={
                "title": "Private Registry",
                "username": "testuser",
                "is_private": True,
            },
            headers=_basic_auth(),
        )
        assert resp.status_code == 201
        # Verify the payload sent to WP had status=private
        call_args = mock_wp.custom_post_type.return_value.create.call_args
        assert call_args[0][0]["status"] == "private"

    def test_create_requires_auth(self, client):
        resp = client.post("/registries", json={"title": "No Auth", "username": "x"})
        assert resp.status_code == 401

    def test_create_validation_error(self, client, mock_auth, mock_wp):
        resp = client.post(
            "/registries",
            json={"title": "", "username": "testuser"},
            headers=_basic_auth(),
        )
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# LIST
# ---------------------------------------------------------------------------

class TestListRegistries:
    def test_list_own_registries(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.list.return_value = [
            _wp_post(id=1, author=1, title="Reg 1"),
            _wp_post(id=2, author=1, title="Reg 2"),
        ]
        resp = client.get("/registries", headers=_basic_auth())
        assert resp.status_code == 200
        data = resp.json()
        assert len(data) == 2
        assert data[0]["id"] == 1
        assert data[1]["id"] == 2

    def test_list_pagination(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.list.return_value = []
        resp = client.get("/registries?page=2&per_page=5", headers=_basic_auth())
        assert resp.status_code == 200
        call_kwargs = mock_wp.custom_post_type.return_value.list.call_args
        assert call_kwargs[1]["page"] == 2
        assert call_kwargs[1]["per_page"] == 5

    def test_list_empty(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.list.return_value = []
        resp = client.get("/registries", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json() == []


# ---------------------------------------------------------------------------
# GET
# ---------------------------------------------------------------------------

class TestGetRegistry:
    def test_get_own_registry(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            id=100, author=1
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["id"] == 100

    def test_get_as_invitee(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            id=100,
            author=99,  # not the authenticated user
            meta={
                "restart_invitees": '["testuser"]',
                "restart_item_ids": "[]",
            },
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200

    def test_get_forbidden(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            id=100, author=99
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 403

    def test_get_not_found(self, client, mock_auth, mock_wp):
        from wp_python.exceptions import NotFoundError
        mock_wp.custom_post_type.return_value.get.side_effect = NotFoundError(
            404, {"message": "Not found"}
        )
        resp = client.get("/registries/999", headers=_basic_auth())
        assert resp.status_code == 404

    def test_get_as_admin(self, client, mock_wp):
        """Admins can view any registry."""
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=1, roles=["administrator"])
            mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
                id=100, author=99
            )
            resp = client.get("/registries/100", headers=_basic_auth())
            assert resp.status_code == 200


# ---------------------------------------------------------------------------
# UPDATE
# ---------------------------------------------------------------------------

class TestUpdateRegistry:
    def test_update_title(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1, title="New Title")

        resp = client.patch(
            "/registries/100",
            json={"title": "New Title"},
            headers=_basic_auth(),
        )
        assert resp.status_code == 200
        assert resp.json()["title"] == "New Title"

    def test_update_privacy(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1, status="private")

        resp = client.patch(
            "/registries/100",
            json={"is_private": True},
            headers=_basic_auth(),
        )
        assert resp.status_code == 200
        call_args = cpt.update.call_args
        assert call_args[0][1]["status"] == "private"

    def test_update_forbidden(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            id=100, author=99
        )
        resp = client.patch(
            "/registries/100",
            json={"title": "Nope"},
            headers=_basic_auth(),
        )
        assert resp.status_code == 403

    def test_update_not_found(self, client, mock_auth, mock_wp):
        from wp_python.exceptions import NotFoundError
        mock_wp.custom_post_type.return_value.get.side_effect = NotFoundError(
            404, {"message": "Not found"}
        )
        resp = client.patch(
            "/registries/100",
            json={"title": "Gone"},
            headers=_basic_auth(),
        )
        assert resp.status_code == 404

    def test_update_empty_body_returns_existing(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1, title="Original")

        resp = client.patch(
            "/registries/100",
            json={},
            headers=_basic_auth(),
        )
        assert resp.status_code == 200
        assert resp.json()["title"] == "Original"
        cpt.update.assert_not_called()


# ---------------------------------------------------------------------------
# DELETE
# ---------------------------------------------------------------------------

class TestDeleteRegistry:
    def test_delete_own(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.delete.return_value = {}

        resp = client.delete("/registries/100", headers=_basic_auth())
        assert resp.status_code == 204
        cpt.delete.assert_called_once_with(100, force=True)

    def test_delete_forbidden(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            id=100, author=99
        )
        resp = client.delete("/registries/100", headers=_basic_auth())
        assert resp.status_code == 403

    def test_delete_not_found(self, client, mock_auth, mock_wp):
        from wp_python.exceptions import NotFoundError
        mock_wp.custom_post_type.return_value.get.side_effect = NotFoundError(
            404, {"message": "Not found"}
        )
        resp = client.delete("/registries/999", headers=_basic_auth())
        assert resp.status_code == 404

    def test_delete_as_admin(self, client, mock_wp):
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=1, roles=["administrator"])
            cpt = mock_wp.custom_post_type.return_value
            cpt.get.return_value = _wp_post(id=100, author=99)
            cpt.delete.return_value = {}

            resp = client.delete("/registries/100", headers=_basic_auth())
            assert resp.status_code == 204


# ---------------------------------------------------------------------------
# REGISTRY ITEMS (nested)
# ---------------------------------------------------------------------------

class TestRegistryItems:
    def test_add_item_to_registry(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1)

        resp = client.post(
            "/registries/100/items",
            json={"name": "Toaster", "url": "https://example.com/toaster", "price": 29.99},
            headers=_basic_auth(),
        )
        assert resp.status_code == 201
        data = resp.json()
        assert data["success"] is True
        assert data["data"]["name"] == "Toaster"
        assert data["data"]["registry_id"] == 100

    def test_list_registry_items(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1)

        # Add two items
        client.post(
            "/registries/100/items",
            json={"name": "Item A", "url": "https://example.com/a", "price": 10.0},
            headers=_basic_auth(),
        )
        client.post(
            "/registries/100/items",
            json={"name": "Item B", "url": "https://example.com/b", "price": 20.0},
            headers=_basic_auth(),
        )

        resp = client.get("/registries/100/items", headers=_basic_auth())
        assert resp.status_code == 200
        items = resp.json()
        assert len(items) == 2
        assert all(i["registry_id"] == 100 for i in items)

    def test_remove_item_from_registry(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1)

        # Add then remove
        resp = client.post(
            "/registries/100/items",
            json={"name": "Removable", "url": "https://example.com/rm", "price": 5.0},
            headers=_basic_auth(),
        )
        item_id = resp.json()["data"]["id"]

        del_resp = client.delete(f"/registries/100/items/{item_id}", headers=_basic_auth())
        assert del_resp.status_code == 204

        # Verify it's gone
        list_resp = client.get("/registries/100/items", headers=_basic_auth())
        assert len(list_resp.json()) == 0

    def test_remove_item_not_in_registry(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)

        resp = client.delete("/registries/100/items/9999", headers=_basic_auth())
        assert resp.status_code == 404

    def test_add_item_forbidden_for_invitee(self, client, mock_wp):
        """Invitees can view items but not add them."""
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=5, username="invitee")
            cpt = mock_wp.custom_post_type.return_value
            cpt.get.return_value = _wp_post(
                id=100,
                author=99,
                meta={"restart_invitees": '["invitee"]', "restart_item_ids": "[]"},
            )

            resp = client.post(
                "/registries/100/items",
                json={"name": "Nope", "url": "https://example.com/no", "price": 1.0},
                headers=_basic_auth(),
            )
            assert resp.status_code == 403

    def test_list_items_as_invitee(self, client, mock_wp):
        """Invitees can list registry items."""
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=5, username="invitee")
            cpt = mock_wp.custom_post_type.return_value
            cpt.get.return_value = _wp_post(
                id=100,
                author=99,
                meta={"restart_invitees": '["invitee"]', "restart_item_ids": "[]"},
            )

            resp = client.get("/registries/100/items", headers=_basic_auth())
            assert resp.status_code == 200

    def test_list_items_forbidden_non_member(self, client, mock_wp):
        """Non-members cannot list items."""
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=5, username="stranger")
            cpt = mock_wp.custom_post_type.return_value
            cpt.get.return_value = _wp_post(id=100, author=99)

            resp = client.get("/registries/100/items", headers=_basic_auth())
            assert resp.status_code == 403


# ---------------------------------------------------------------------------
# INVITEES
# ---------------------------------------------------------------------------

class TestInvitees:
    def test_list_invitees(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(
            id=100, author=1,
            meta={"restart_invitees": '["alice", "bob"]', "restart_item_ids": "[]"},
        )
        resp = client.get("/registries/100/invitees", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["invitees"] == ["alice", "bob"]

    def test_add_invitees(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(
            id=100, author=1,
            meta={"restart_invitees": '["alice"]', "restart_item_ids": "[]"},
        )
        cpt.update.return_value = _wp_post(id=100, author=1)

        resp = client.post(
            "/registries/100/invitees",
            json={"invitees": ["bob", "carol"]},
            headers=_basic_auth(),
        )
        assert resp.status_code == 200
        invitees = resp.json()["invitees"]
        assert "alice" in invitees
        assert "bob" in invitees
        assert "carol" in invitees

    def test_add_invitees_deduplicates(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(
            id=100, author=1,
            meta={"restart_invitees": '["alice"]', "restart_item_ids": "[]"},
        )
        cpt.update.return_value = _wp_post(id=100, author=1)

        resp = client.post(
            "/registries/100/invitees",
            json={"invitees": ["alice", "bob"]},
            headers=_basic_auth(),
        )
        assert resp.status_code == 200
        assert resp.json()["invitees"].count("alice") == 1

    def test_remove_invitee(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(
            id=100, author=1,
            meta={"restart_invitees": '["alice", "bob"]', "restart_item_ids": "[]"},
        )
        cpt.update.return_value = _wp_post(id=100, author=1)

        resp = client.delete("/registries/100/invitees/alice", headers=_basic_auth())
        assert resp.status_code == 200
        assert "alice" not in resp.json()["invitees"]
        assert "bob" in resp.json()["invitees"]

    def test_remove_invitee_not_found(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(
            id=100, author=1,
            meta={"restart_invitees": '["alice"]', "restart_item_ids": "[]"},
        )

        resp = client.delete("/registries/100/invitees/nonexistent", headers=_basic_auth())
        assert resp.status_code == 404

    def test_add_invitees_forbidden_non_owner(self, client, mock_wp):
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=5, username="invitee")
            cpt = mock_wp.custom_post_type.return_value
            cpt.get.return_value = _wp_post(
                id=100, author=99,
                meta={"restart_invitees": '["invitee"]', "restart_item_ids": "[]"},
            )

            resp = client.post(
                "/registries/100/invitees",
                json={"invitees": ["newguy"]},
                headers=_basic_auth(),
            )
            assert resp.status_code == 403


# ---------------------------------------------------------------------------
# AFFILIATE LINKS
# ---------------------------------------------------------------------------

class TestAffiliateLinks:
    def _create_item_in_registry(self, client, mock_wp):
        """Helper to create an item linked to registry 100."""
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1)

        resp = client.post(
            "/registries/100/items",
            json={"name": "Test Item", "url": "https://example.com/item", "price": 49.99},
            headers=_basic_auth(),
        )
        return resp.json()["data"]["id"]

    def test_set_affiliate_link(self, client, mock_auth, mock_wp):
        item_id = self._create_item_in_registry(client, mock_wp)

        resp = client.put(
            f"/registries/100/items/{item_id}/affiliate",
            json={"affiliate_url": "https://affiliate.example.com/ref123", "affiliate_status": "active"},
            headers=_basic_auth(),
        )
        assert resp.status_code == 200
        data = resp.json()
        assert data["affiliate_url"] == "https://affiliate.example.com/ref123"
        assert data["affiliate_status"] == "active"
        assert data["item_id"] == item_id

    def test_get_affiliate_link(self, client, mock_auth, mock_wp):
        item_id = self._create_item_in_registry(client, mock_wp)

        # Set it first
        client.put(
            f"/registries/100/items/{item_id}/affiliate",
            json={"affiliate_url": "https://affiliate.example.com/ref456"},
            headers=_basic_auth(),
        )

        # Get it
        resp = client.get(f"/registries/100/items/{item_id}/affiliate", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["affiliate_url"] == "https://affiliate.example.com/ref456"

    def test_remove_affiliate_link(self, client, mock_auth, mock_wp):
        item_id = self._create_item_in_registry(client, mock_wp)

        # Set then remove
        client.put(
            f"/registries/100/items/{item_id}/affiliate",
            json={"affiliate_url": "https://affiliate.example.com/ref789"},
            headers=_basic_auth(),
        )
        resp = client.delete(f"/registries/100/items/{item_id}/affiliate", headers=_basic_auth())
        assert resp.status_code == 204

        # Verify cleared
        get_resp = client.get(f"/registries/100/items/{item_id}/affiliate", headers=_basic_auth())
        assert get_resp.json()["affiliate_url"] is None
        assert get_resp.json()["affiliate_status"] is None

    def test_affiliate_item_not_found(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)

        resp = client.get("/registries/100/items/9999/affiliate", headers=_basic_auth())
        assert resp.status_code == 404

    def test_set_affiliate_forbidden_non_owner(self, client, mock_wp):
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=5, username="invitee")
            cpt = mock_wp.custom_post_type.return_value
            cpt.get.return_value = _wp_post(
                id=100, author=99,
                meta={"restart_invitees": '["invitee"]', "restart_item_ids": "[]"},
            )

            resp = client.put(
                "/registries/100/items/1/affiliate",
                json={"affiliate_url": "https://evil.com/ref"},
                headers=_basic_auth(),
            )
            assert resp.status_code == 403

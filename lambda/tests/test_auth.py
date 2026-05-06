"""Unit tests for WordPress authentication integration."""

import base64
import os

os.environ.setdefault("DATABASE_PATH", ":memory:")

import pytest
from unittest.mock import patch, MagicMock
from fastapi import FastAPI, Depends
from fastapi.testclient import TestClient

from app.auth.models import WPUser
from app.auth.dependencies import get_current_user, require_roles
from app.auth.wp_client import validate_credentials, clear_cache


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

def _make_wp_user(**overrides) -> WPUser:
    defaults = {
        "id": 42,
        "username": "testuser",
        "email": "test@example.com",
        "display_name": "Test User",
        "roles": ["subscriber"],
        "capabilities": {"read": True},
    }
    defaults.update(overrides)
    return WPUser(**defaults)


def _basic_auth_header(username: str, password: str) -> str:
    creds = base64.b64encode(f"{username}:{password}".encode()).decode()
    return f"Basic {creds}"


@pytest.fixture(autouse=True)
def _clear_auth_cache():
    clear_cache()
    yield
    clear_cache()


@pytest.fixture
def auth_app():
    """Minimal FastAPI app with auth-protected routes for testing."""
    app = FastAPI()

    @app.get("/me")
    async def me(user: WPUser = Depends(get_current_user)):
        return {"id": user.id, "username": user.username, "roles": user.roles}

    @app.get("/admin-only")
    async def admin_only(user: WPUser = Depends(require_roles("administrator"))):
        return {"id": user.id, "admin": True}

    @app.get("/editor-or-admin")
    async def editor_or_admin(
        user: WPUser = Depends(require_roles("administrator", "editor"))
    ):
        return {"id": user.id}

    return app


@pytest.fixture
def client(auth_app):
    return TestClient(auth_app)


# ---------------------------------------------------------------------------
# WPUser model tests
# ---------------------------------------------------------------------------

class TestWPUserModel:
    def test_has_role(self):
        user = _make_wp_user(roles=["subscriber", "editor"])
        assert user.has_role("editor")
        assert not user.has_role("administrator")

    def test_has_capability(self):
        user = _make_wp_user(capabilities={"edit_posts": True, "delete_posts": False})
        assert user.has_capability("edit_posts")
        assert not user.has_capability("delete_posts")
        assert not user.has_capability("nonexistent")

    def test_is_admin(self):
        assert not _make_wp_user(roles=["subscriber"]).is_admin
        assert _make_wp_user(roles=["administrator"]).is_admin


# ---------------------------------------------------------------------------
# Dependency tests (with mocked WP validation)
# ---------------------------------------------------------------------------

class TestAuthDependency:
    def test_missing_auth_header(self, client):
        resp = client.get("/me")
        assert resp.status_code == 401
        assert "Missing Authorization" in resp.json()["detail"]
        assert resp.headers["WWW-Authenticate"] == "Basic"

    @patch("app.auth.dependencies.validate_credentials")
    def test_invalid_credentials(self, mock_validate, client):
        mock_validate.return_value = None
        resp = client.get("/me", headers={"Authorization": "Basic bad"})
        assert resp.status_code == 401
        assert "Invalid WordPress credentials" in resp.json()["detail"]

    @patch("app.auth.dependencies.validate_credentials")
    def test_valid_credentials(self, mock_validate, client):
        mock_validate.return_value = _make_wp_user()
        header = _basic_auth_header("testuser", "xxxx xxxx xxxx")
        resp = client.get("/me", headers={"Authorization": header})
        assert resp.status_code == 200
        data = resp.json()
        assert data["id"] == 42
        assert data["username"] == "testuser"

    @patch("app.auth.dependencies.validate_credentials")
    def test_require_roles_forbidden(self, mock_validate, client):
        mock_validate.return_value = _make_wp_user(roles=["subscriber"])
        header = _basic_auth_header("testuser", "xxxx")
        resp = client.get("/admin-only", headers={"Authorization": header})
        assert resp.status_code == 403
        assert "administrator" in resp.json()["detail"]

    @patch("app.auth.dependencies.validate_credentials")
    def test_require_roles_allowed(self, mock_validate, client):
        mock_validate.return_value = _make_wp_user(roles=["administrator"])
        header = _basic_auth_header("admin", "xxxx")
        resp = client.get("/admin-only", headers={"Authorization": header})
        assert resp.status_code == 200
        assert resp.json()["admin"] is True

    @patch("app.auth.dependencies.validate_credentials")
    def test_require_multiple_roles_any_match(self, mock_validate, client):
        mock_validate.return_value = _make_wp_user(roles=["editor"])
        header = _basic_auth_header("editor", "xxxx")
        resp = client.get("/editor-or-admin", headers={"Authorization": header})
        assert resp.status_code == 200


# ---------------------------------------------------------------------------
# WP client validation tests (mocking wp_python)
# ---------------------------------------------------------------------------

def _mock_wp_user(**overrides):
    """Create a mock wp_python User object."""
    from wp_python.models.user import User
    defaults = {
        "id": 1,
        "username": "andrew",
        "slug": "andrew",
        "email": "andrew@example.com",
        "name": "Andrew",
        "roles": ["administrator"],
        "capabilities": {"manage_options": True},
    }
    defaults.update(overrides)
    return User.model_validate(defaults)


class TestValidateCredentials:
    @patch("app.auth.wp_client.get_wp_client")
    def test_successful_validation(self, mock_get_client):
        mock_client = MagicMock()
        mock_client.__enter__.return_value = mock_client
        mock_client.users.me.return_value = _mock_wp_user()
        mock_get_client.return_value = mock_client

        # "test:test" base64-encoded
        user = validate_credentials("Basic dGVzdDp0ZXN0")
        assert user is not None
        assert user.id == 1
        assert user.username == "andrew"
        assert user.is_admin
        mock_client.__exit__.assert_called_once()

    @patch("app.auth.wp_client.get_wp_client")
    def test_invalid_credentials_returns_none(self, mock_get_client):
        from wp_python.exceptions import AuthenticationError
        mock_client = MagicMock()
        mock_client.__enter__.return_value = mock_client
        mock_client.users.me.side_effect = AuthenticationError("Invalid credentials", 401)
        mock_get_client.return_value = mock_client

        user = validate_credentials("Basic dGVzdDp0ZXN0")
        assert user is None

    def test_malformed_auth_header_returns_none(self):
        user = validate_credentials("Bearer some-token")
        assert user is None

    def test_invalid_base64_returns_none(self):
        user = validate_credentials("Basic !!!invalid!!!")
        assert user is None

    @patch("app.auth.wp_client.get_wp_client")
    def test_wp_unreachable_returns_none(self, mock_get_client):
        mock_get_client.side_effect = Exception("Connection refused")

        user = validate_credentials("Basic dGVzdDp0ZXN0")
        assert user is None

    @patch("app.auth.wp_client.get_wp_client")
    def test_caching(self, mock_get_client):
        mock_client = MagicMock()
        mock_client.__enter__.return_value = mock_client
        mock_client.users.me.return_value = _mock_wp_user(
            id=5, slug="cached", email="c@e.com", name="Cached",
            roles=["subscriber"], capabilities={},
        )
        mock_get_client.return_value = mock_client

        # First call hits WP
        user1 = validate_credentials("Basic Y2FjaGVkOnB3ZA==")
        assert user1 is not None
        assert mock_get_client.call_count == 1

        # Second call uses cache
        user2 = validate_credentials("Basic Y2FjaGVkOnB3ZA==")
        assert user2 is not None
        assert user2.id == user1.id
        assert mock_get_client.call_count == 1  # no additional call

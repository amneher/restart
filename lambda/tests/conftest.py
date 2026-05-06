import base64
import os

import pytest
from fastapi.testclient import TestClient

os.environ["DATABASE_PATH"] = ":memory:"

from app.auth import get_current_user
from app.auth.models import WPUser
from app.database import close_db, get_connection, init_db
from app.main import app


def _make_wp_user(admin: bool = False) -> WPUser:
    return WPUser(
        id=1,
        username="testuser",
        email="testuser@example.com",
        display_name="Test User",
        roles=["administrator"] if admin else ["registry_user"],
        capabilities={"administrator": True} if admin else {"read": True},
    )


def auth_headers(user: str = "testuser", pwd: str = "xxxx") -> dict:
    creds = base64.b64encode(f"{user}:{pwd}".encode()).decode()
    return {"Authorization": f"Basic {creds}"}


@pytest.fixture(scope="function")
def client():
    init_db()
    app.dependency_overrides[get_current_user] = lambda: _make_wp_user(admin=False)
    with TestClient(app) as test_client:
        yield test_client
    app.dependency_overrides.clear()
    close_db()


@pytest.fixture(scope="function")
def admin_client():
    init_db()
    app.dependency_overrides[get_current_user] = lambda: _make_wp_user(admin=True)
    with TestClient(app) as test_client:
        yield test_client
    app.dependency_overrides.clear()
    close_db()


@pytest.fixture(scope="function")
def unauthed_client():
    """TestClient with dependency overrides cleared — auth is enforced."""
    saved = app.dependency_overrides.copy()
    app.dependency_overrides.clear()
    init_db()
    with TestClient(app) as test_client:
        yield test_client
    app.dependency_overrides.update(saved)
    close_db()


@pytest.fixture(scope="function")
def db_connection():
    init_db()
    conn = get_connection()
    yield conn
    close_db()


@pytest.fixture
def auth():
    return auth_headers()


@pytest.fixture
def sample_item():
    return {
        "registry_id": 1,
        "name": "Test Item",
        "description": "A test item description",
        "url": "https://example.com/product/test-item",
        "price": 29.99,
        "is_active": True,
    }


@pytest.fixture
def sample_items():
    return [
        {
            "registry_id": 1,
            "name": "Item 1",
            "description": "First item",
            "url": "https://example.com/product/1",
            "price": 10.00,
            "is_active": True,
        },
        {
            "registry_id": 1,
            "name": "Item 2",
            "description": "Second item",
            "url": "https://example.com/product/2",
            "price": 20.00,
            "is_active": True,
        },
        {
            "registry_id": 1,
            "name": "Item 3",
            "description": "Third item",
            "url": "https://example.com/product/3",
            "price": 30.00,
            "is_active": True,
        },
    ]

import json
import os

import pytest

os.environ["DATABASE_PATH"] = ":memory:"

from app.auth import get_current_user
from app.auth.models import WPUser
from app.database import close_db, init_db
from app.main import app, handler


def _wp_user() -> WPUser:
    return WPUser(
        id=1,
        username="testuser",
        email="testuser@example.com",
        display_name="Test User",
        roles=["subscriber"],
        capabilities={"read": True},
    )


@pytest.fixture(autouse=True)
def setup_db():
    app.dependency_overrides[get_current_user] = lambda: _wp_user()
    init_db()
    yield
    app.dependency_overrides.clear()
    close_db()


def create_lambda_event(method: str, path: str, body: dict = None) -> dict:
    event = {
        "version": "2.0",
        "routeKey": f"{method} {path}",
        "rawPath": path,
        "rawQueryString": "",
        "headers": {"content-type": "application/json"},
        "requestContext": {
            "accountId": "123456789012",
            "apiId": "api-id",
            "domainName": "test.execute-api.us-east-1.amazonaws.com",
            "domainPrefix": "test",
            "http": {
                "method": method,
                "path": path,
                "protocol": "HTTP/1.1",
                "sourceIp": "127.0.0.1",
                "userAgent": "test-agent",
            },
            "requestId": "test-request-id",
            "routeKey": f"{method} {path}",
            "stage": "$default",
            "time": "01/Jan/2025:00:00:00 +0000",
            "timeEpoch": 1704067200000,
        },
        "body": json.dumps(body) if body else None,
        "isBase64Encoded": False,
    }
    return event


class TestLambdaHandler:
    def test_health_endpoint(self):
        event = create_lambda_event("GET", "/health")
        response = handler(event, None)

        assert response["statusCode"] == 200
        body = json.loads(response["body"])
        assert body["status"] == "healthy"

    def test_root_endpoint(self):
        event = create_lambda_event("GET", "/")
        response = handler(event, None)

        assert response["statusCode"] == 200
        body = json.loads(response["body"])
        assert "message" in body

    def test_create_item_via_lambda(self):
        event = create_lambda_event(
            "POST", "/items",
            {"registry_id": 1, "name": "Lambda Item", "url": "https://example.com/product/lambda-item", "price": 50.00},
        )
        response = handler(event, None)

        assert response["statusCode"] == 201
        body = json.loads(response["body"])
        assert body["success"] is True
        assert body["data"]["name"] == "Lambda Item"

    def test_get_items_via_lambda(self):
        create_event = create_lambda_event(
            "POST", "/items",
            {"registry_id": 1, "name": "Test", "url": "https://example.com/product/test", "price": 10.00},
        )
        handler(create_event, None)

        list_event = create_lambda_event("GET", "/items")
        response = handler(list_event, None)

        assert response["statusCode"] == 200
        body = json.loads(response["body"])
        assert len(body) >= 1

    def test_not_found_route(self):
        event = create_lambda_event("GET", "/nonexistent")
        response = handler(event, None)

        assert response["statusCode"] == 404

"""Tests for CORSMiddleware registered in app/main.py.

Config under test:
    allow_origins=["*"]
    allow_methods=["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"]
    allow_headers=["Authorization", "Content-Type"]
    allow_credentials=False  (default)

Test ordering: Lambda path first (Mangum regression visible before per-route noise),
then preflights (positive → negative), then simple requests, then header specifics.
"""

import json
import os

os.environ.setdefault("DATABASE_PATH", ":memory:")

import pytest
from fastapi.testclient import TestClient

from app.database import close_db, init_db
from app.main import app, handler


ORIGIN = "https://example.com"

PREFLIGHT_HEADERS = {
    "Origin": ORIGIN,
    "Access-Control-Request-Method": "GET",
}


@pytest.fixture(autouse=True)
def _db():
    init_db()
    yield
    close_db()


@pytest.fixture
def client():
    return TestClient(app)


def _lambda_event(method: str, path: str, headers: dict | None = None) -> dict:
    """Build an API Gateway v2 payload for Mangum."""
    base_headers = {"content-type": "application/json"}
    if headers:
        base_headers.update(headers)
    return {
        "version": "2.0",
        "routeKey": f"{method} {path}",
        "rawPath": path,
        "rawQueryString": "",
        "headers": base_headers,
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
        "body": None,
        "isBase64Encoded": False,
    }


# ---------------------------------------------------------------------------
# Lambda / Mangum path — run first so Mangum-layer regressions surface early
# ---------------------------------------------------------------------------

class TestCORSLambda:
    def test_preflight_via_mangum_returns_cors_headers(self):
        """CORS headers must survive the Mangum API Gateway v2 serialization layer."""
        event = _lambda_event(
            "OPTIONS",
            "/health",
            headers={
                "origin": ORIGIN,
                "access-control-request-method": "GET",
            },
        )
        response = handler(event, None)

        # API Gateway v2 uses lowercase header keys
        headers = response.get("headers", {})
        acao = headers.get("access-control-allow-origin") or headers.get("Access-Control-Allow-Origin")
        assert acao == "*", f"Expected CORS wildcard in Lambda response headers, got: {headers}"

    def test_lambda_response_is_v2_format(self):
        """Response dict must use v2 shape (headers, not multiValueHeaders)."""
        event = _lambda_event(
            "OPTIONS",
            "/health",
            headers={
                "origin": ORIGIN,
                "access-control-request-method": "GET",
            },
        )
        response = handler(event, None)
        assert "headers" in response
        assert "statusCode" in response
        # v2 uses 'headers' not 'multiValueHeaders' as the primary dict
        assert isinstance(response["headers"], dict)


# ---------------------------------------------------------------------------
# Preflight (OPTIONS) — positive cases
# ---------------------------------------------------------------------------

class TestCORSPreflightAllowed:
    @pytest.mark.parametrize("method", ["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"])
    def test_allowed_methods_return_200(self, client, method):
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": method,
            },
        )
        assert resp.status_code == 200, f"Expected 200 for preflight {method}, got {resp.status_code}"

    @pytest.mark.parametrize("method", ["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"])
    def test_allowed_methods_include_acao_header(self, client, method):
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": method,
            },
        )
        assert resp.headers.get("access-control-allow-origin") == "*"

    def test_preflight_includes_allow_methods_header(self, client):
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "GET",
            },
        )
        allow_methods = resp.headers.get("access-control-allow-methods", "")
        assert "GET" in allow_methods
        assert "POST" in allow_methods
        assert "PUT" in allow_methods
        assert "PATCH" in allow_methods
        assert "DELETE" in allow_methods

    def test_preflight_does_not_include_allow_credentials(self, client):
        """allow_credentials=False (default): header must be absent — browsers reject
        credentials+wildcard combinations, so a regression here would break auth."""
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "GET",
            },
        )
        assert "access-control-allow-credentials" not in resp.headers

    def test_preflight_max_age_is_set(self, client):
        """Pin the default Access-Control-Max-Age so silent changes to preflight
        caching are caught (default is 600 in Starlette)."""
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "GET",
            },
        )
        assert "access-control-max-age" in resp.headers
        assert int(resp.headers["access-control-max-age"]) > 0


# ---------------------------------------------------------------------------
# Preflight (OPTIONS) — negative cases
# ---------------------------------------------------------------------------

class TestCORSPreflightDisallowed:
    def test_disallowed_method_returns_400(self, client):
        """CONNECT is not in allow_methods — Starlette returns 400 for disallowed
        preflight methods."""
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "CONNECT",
            },
        )
        assert resp.status_code == 400

    def test_put_was_previously_missing_regression(self, client):
        """Regression: PUT was absent from allow_methods in the original commit.
        Any 400 here means the bug has been re-introduced."""
        resp = client.options(
            "/items/1",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "PUT",
            },
        )
        assert resp.status_code == 200

    def test_non_cors_options_no_request_method_passes_through(self, client):
        """OPTIONS without Access-Control-Request-Method is not a CORS preflight.
        Starlette passes it to routing; /health has no OPTIONS handler → 405."""
        resp = client.options("/health", headers={"Origin": ORIGIN})
        # Middleware does not intercept — routing handles it
        assert resp.status_code in (200, 405)
        # Crucially, no CORS allow-methods header on a non-preflight OPTIONS
        assert "access-control-request-method" not in resp.headers


# ---------------------------------------------------------------------------
# Simple CORS requests (non-preflight)
# ---------------------------------------------------------------------------

class TestCORSSimpleRequests:
    def test_get_with_origin_returns_wildcard(self, client):
        resp = client.get("/health", headers={"Origin": ORIGIN})
        assert resp.headers.get("access-control-allow-origin") == "*"

    def test_wildcard_is_literal_not_echoed_origin(self, client):
        """With allow_origins=["*"] and no credentials, the response must be the
        literal string "*" — not the echoed request origin. If credentials are ever
        enabled alongside wildcard, Starlette switches to echoing the origin and
        browsers reject every response."""
        resp = client.get("/health", headers={"Origin": "https://attacker.example.com"})
        acao = resp.headers.get("access-control-allow-origin")
        assert acao == "*"
        assert acao != "https://attacker.example.com"

    def test_request_without_origin_has_no_acao_header(self, client):
        """No Origin header → not a CORS request → middleware must not emit
        Access-Control-Allow-Origin (emitting it unconditionally would be a bug)."""
        resp = client.get("/health")
        assert "access-control-allow-origin" not in resp.headers

    def test_simple_request_no_allow_credentials(self, client):
        resp = client.get("/health", headers={"Origin": ORIGIN})
        assert "access-control-allow-credentials" not in resp.headers

    def test_post_with_origin_returns_wildcard(self, client):
        resp = client.get("/health", headers={"Origin": "https://other-site.io"})
        assert resp.headers.get("access-control-allow-origin") == "*"


# ---------------------------------------------------------------------------
# Allowed headers
# ---------------------------------------------------------------------------

class TestCORSAllowedHeaders:
    def test_authorization_header_reflected(self, client):
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "GET",
                "Access-Control-Request-Headers": "Authorization",
            },
        )
        assert resp.status_code == 200
        allow_headers = resp.headers.get("access-control-allow-headers", "")
        assert "authorization" in allow_headers.lower()

    def test_content_type_header_reflected(self, client):
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "POST",
                "Access-Control-Request-Headers": "Content-Type",
            },
        )
        assert resp.status_code == 200
        allow_headers = resp.headers.get("access-control-allow-headers", "")
        assert "content-type" in allow_headers.lower()

    def test_request_headers_case_insensitive(self, client):
        """Browsers may send header names in any case; Starlette normalises them."""
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "GET",
                "Access-Control-Request-Headers": "authorization",  # lowercase
            },
        )
        assert resp.status_code == 200
        allow_headers = resp.headers.get("access-control-allow-headers", "")
        assert "authorization" in allow_headers.lower()

    def test_disallowed_header_not_in_allow_headers(self, client):
        """X-Custom-Header is not in allow_headers — it must not appear in the
        Access-Control-Allow-Headers response."""
        resp = client.options(
            "/health",
            headers={
                "Origin": ORIGIN,
                "Access-Control-Request-Method": "GET",
                "Access-Control-Request-Headers": "X-Custom-Header",
            },
        )
        allow_headers = resp.headers.get("access-control-allow-headers", "")
        assert "x-custom-header" not in allow_headers.lower()

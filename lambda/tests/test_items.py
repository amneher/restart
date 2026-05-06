

class TestCreateItem:
    def test_create_item_success(self, client, auth, sample_item):
        response = client.post("/items", json=sample_item, headers=auth)
        assert response.status_code == 201
        data = response.json()
        assert data["success"] is True
        assert data["data"]["name"] == sample_item["name"]
        assert data["data"]["description"] == sample_item["description"]
        assert data["data"]["price"] == sample_item["price"]
        assert data["data"]["is_active"] == sample_item["is_active"]
        assert "id" in data["data"]
        assert "created_at" in data["data"]
        assert "updated_at" in data["data"]

    def test_create_item_without_description(self, client, auth):
        item = {"registry_id": 1, "name": "No Description", "url": "https://example.com/product/no-desc"}
        response = client.post("/items", json=item, headers=auth)
        assert response.status_code == 201
        data = response.json()
        assert data["success"] is True
        assert data["data"]["description"] is None

    def test_create_item_requires_auth(self, unauthed_client, sample_item):
        response = unauthed_client.post("/items", json=sample_item)
        assert response.status_code == 401

    def test_create_item_missing_registry_id(self, client, auth):
        item = {"name": "No Registry", "url": "https://example.com/product/x", "price": 10.00}
        response = client.post("/items", json=item, headers=auth)
        assert response.status_code == 422

    def test_create_item_invalid_price(self, client, auth):
        item = {"registry_id": 1, "name": "Invalid", "url": "https://example.com/product/x", "price": -10.00}
        response = client.post("/items", json=item, headers=auth)
        assert response.status_code == 422

    def test_create_item_missing_name(self, client, auth):
        item = {"registry_id": 1, "url": "https://example.com/product/x"}
        response = client.post("/items", json=item, headers=auth)
        assert response.status_code == 422

    def test_create_item_empty_name(self, client, auth):
        item = {"registry_id": 1, "name": "", "url": "https://example.com/product/x"}
        response = client.post("/items", json=item, headers=auth)
        assert response.status_code == 422

    def test_affiliate_fields_hidden_from_regular_user(self, client, auth, sample_item):
        item = {**sample_item, "affiliate_url": "https://aff.example.com/x", "affiliate_status": "active"}
        response = client.post("/items", json=item, headers=auth)
        assert response.status_code == 201
        data = response.json()["data"]
        assert "affiliate_url" not in data
        assert "affiliate_status" not in data

    def test_affiliate_fields_visible_to_admin(self, admin_client, auth, sample_item):
        item = {**sample_item, "affiliate_url": "https://aff.example.com/x", "affiliate_status": "active"}
        response = admin_client.post("/items", json=item, headers=auth)
        assert response.status_code == 201
        data = response.json()["data"]
        assert data["affiliate_url"] == "https://aff.example.com/x"
        assert data["affiliate_status"] == "active"


class TestGetItem:
    def test_get_item_success(self, client, auth, sample_item):
        create_response = client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        response = client.get(f"/items/{item_id}", headers=auth)
        assert response.status_code == 200
        data = response.json()
        assert data["success"] is True
        assert data["data"]["id"] == item_id
        assert data["data"]["name"] == sample_item["name"]

    def test_get_item_not_found(self, client, auth):
        response = client.get("/items/99999", headers=auth)
        assert response.status_code == 404
        assert "not found" in response.json()["detail"].lower()

    def test_get_item_requires_auth(self, unauthed_client):
        response = unauthed_client.get("/items/1")
        assert response.status_code == 401


class TestListItems:
    def test_list_items_empty(self, client, auth):
        response = client.get("/items", headers=auth)
        assert response.status_code == 200
        assert response.json() == []

    def test_list_items_with_data(self, client, auth, sample_items):
        for item in sample_items:
            client.post("/items", json=item, headers=auth)

        response = client.get("/items", headers=auth)
        assert response.status_code == 200
        items = response.json()
        assert len(items) == 3

    def test_list_items_pagination(self, client, auth, sample_items):
        for item in sample_items:
            client.post("/items", json=item, headers=auth)

        response = client.get("/items?skip=1&limit=1", headers=auth)
        assert response.status_code == 200
        items = response.json()
        assert len(items) == 1

    def test_list_items_requires_auth(self, unauthed_client):
        response = unauthed_client.get("/items")
        assert response.status_code == 401


class TestUpdateItem:
    def test_update_item_success(self, client, auth, sample_item):
        create_response = client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        update_data = {"name": "Updated Name", "price": 99.99}
        response = client.put(f"/items/{item_id}", json=update_data, headers=auth)
        assert response.status_code == 200
        data = response.json()
        assert data["success"] is True
        assert data["data"]["name"] == "Updated Name"
        assert data["data"]["price"] == 99.99
        assert data["data"]["description"] == sample_item["description"]

    def test_update_item_url_and_retailer(self, client, auth, sample_item):
        create_response = client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        response = client.put(f"/items/{item_id}", json={"url": "https://example.com/product/new", "retailer": "ACME"}, headers=auth)
        assert response.status_code == 200
        data = response.json()["data"]
        assert data["url"] == "https://example.com/product/new"
        assert data["retailer"] == "ACME"

    def test_update_item_affiliate_status_enum(self, admin_client, auth, sample_item):
        create_response = admin_client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        response = admin_client.put(f"/items/{item_id}", json={"affiliate_status": "active"}, headers=auth)
        assert response.status_code == 200
        assert response.json()["data"]["affiliate_status"] == "active"

    def test_update_item_affiliate_status_invalid(self, client, auth, sample_item):
        create_response = client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        response = client.put(f"/items/{item_id}", json={"affiliate_status": "bogus"}, headers=auth)
        assert response.status_code == 422

    def test_update_item_partial(self, client, auth, sample_item):
        create_response = client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        response = client.put(f"/items/{item_id}", json={"is_active": False}, headers=auth)
        assert response.status_code == 200
        data = response.json()
        assert data["data"]["is_active"] is False
        assert data["data"]["name"] == sample_item["name"]

    def test_update_item_not_found(self, client, auth):
        response = client.put("/items/99999", json={"name": "Test"}, headers=auth)
        assert response.status_code == 404

    def test_update_item_empty_body(self, client, auth, sample_item):
        create_response = client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        response = client.put(f"/items/{item_id}", json={}, headers=auth)
        assert response.status_code == 200


class TestDeleteItem:
    def test_delete_item_soft_deletes(self, client, auth, sample_item):
        create_response = client.post("/items", json=sample_item, headers=auth)
        item_id = create_response.json()["data"]["id"]

        response = client.delete(f"/items/{item_id}", headers=auth)
        assert response.status_code == 200
        data = response.json()
        assert data["success"] is True
        assert data["message"] == "Item deleted successfully"
        assert data["data"]["is_active"] is False

        get_response = client.get(f"/items/{item_id}", headers=auth)
        assert get_response.status_code == 404

    def test_delete_item_not_found(self, client, auth):
        response = client.delete("/items/99999", headers=auth)
        assert response.status_code == 404

    def test_delete_item_requires_auth(self, unauthed_client):
        response = unauthed_client.delete("/items/1")
        assert response.status_code == 401

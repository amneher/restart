import pytest
from datetime import datetime
from pydantic import ValidationError
from app.models import Item, ItemCreate, ItemUpdate, ItemResponse
from app.models.item import AffiliateStatus, ItemPublic, ItemPublicResponse, ItemRegistryCreate


class TestAffiliateStatus:
    def test_valid_values(self):
        for val in ("active", "inactive", "expired", "none"):
            assert AffiliateStatus(val).value == val

    def test_invalid_value(self):
        with pytest.raises(ValueError):
            AffiliateStatus("bogus")


class TestItemCreate:
    def test_valid_item_create(self):
        item = ItemCreate(registry_id=1, name="Test", description="A test", url="https://example.com/product/test", price=10.00)
        assert item.name == "Test"
        assert item.registry_id == 1
        assert item.price == 10.00
        assert item.is_active is True

    def test_item_create_without_price(self):
        item = ItemCreate(registry_id=1, name="Test", url="https://example.com/product/test")
        assert item.price is None

    def test_item_create_without_description(self):
        item = ItemCreate(registry_id=1, name="Test", url="https://example.com/product/test")
        assert item.description is None

    def test_item_create_missing_registry_id(self):
        with pytest.raises(ValidationError):
            ItemCreate(name="Test", url="https://example.com/product/test")

    def test_item_create_invalid_price(self):
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="Test", url="https://example.com/product/test", price=0)

    def test_item_create_negative_price(self):
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="Test", url="https://example.com/product/test", price=-5.00)

    def test_item_create_empty_name(self):
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="", url="https://example.com/product/test")

    def test_item_create_name_too_long(self):
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="x" * 101, url="https://example.com/product/test")

    def test_item_create_affiliate_status_enum(self):
        item = ItemCreate(registry_id=1, name="Test", url="https://example.com/product/test", affiliate_status="active")
        assert item.affiliate_status == AffiliateStatus.active

    def test_item_create_affiliate_status_invalid(self):
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="Test", url="https://example.com/product/test", affiliate_status="bogus")


class TestItemRegistryCreate:
    def test_no_registry_id_required(self):
        item = ItemRegistryCreate(name="Test", url="https://example.com/product/test")
        assert item.name == "Test"
        assert not hasattr(item, "registry_id")


class TestItemUpdate:
    def test_valid_partial_update(self):
        item = ItemUpdate(name="New Name")
        assert item.name == "New Name"
        assert item.description is None
        assert item.price is None

    def test_empty_update(self):
        item = ItemUpdate()
        assert item.model_dump(exclude_unset=True) == {}

    def test_update_exposes_url_and_retailer(self):
        item = ItemUpdate(url="https://example.com/product/new", retailer="ACME")
        assert item.url == "https://example.com/product/new"
        assert item.retailer == "ACME"

    def test_update_affiliate_fields(self):
        item = ItemUpdate(affiliate_url="https://aff.example.com/x", affiliate_status="expired")
        assert item.affiliate_url == "https://aff.example.com/x"
        assert item.affiliate_status == AffiliateStatus.expired

    def test_update_with_all_fields(self):
        item = ItemUpdate(
            name="Updated",
            description="New desc",
            url="https://example.com/product/new",
            retailer="ACME",
            price=50.00,
            affiliate_status="inactive",
            is_active=False,
        )
        assert item.name == "Updated"
        assert item.price == 50.00
        assert item.is_active is False
        assert item.affiliate_status == AffiliateStatus.inactive


class TestItem:
    def test_valid_item(self):
        now = datetime.now()
        item = Item(
            id=1,
            registry_id=5,
            name="Test",
            description="Desc",
            url="https://example.com/product/test",
            price=10.00,
            is_active=True,
            created_at=now,
            updated_at=now,
        )
        assert item.id == 1
        assert item.registry_id == 5


class TestItemPublic:
    def test_excludes_affiliate_fields(self):
        now = datetime.now()
        full_item = Item(
            id=1,
            registry_id=5,
            name="Test",
            url="https://example.com/product/test",
            affiliate_url="https://aff.example.com/x",
            affiliate_status="active",
            price=10.00,
            is_active=True,
            created_at=now,
            updated_at=now,
        )
        public = ItemPublic.model_validate(full_item)
        assert not hasattr(public, "affiliate_url")
        assert not hasattr(public, "affiliate_status")
        assert public.name == "Test"


class TestItemResponse:
    def test_success_response(self):
        now = datetime.now()
        item = Item(
            id=1,
            registry_id=1,
            name="Test",
            description=None,
            url="https://example.com/product/test",
            price=10.00,
            is_active=True,
            created_at=now,
            updated_at=now,
        )
        response = ItemResponse(success=True, data=item)
        assert response.success is True
        assert response.data.id == 1

    def test_error_response(self):
        response = ItemResponse(success=False, message="Not found")
        assert response.success is False
        assert response.data is None
        assert response.message == "Not found"

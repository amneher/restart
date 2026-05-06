from datetime import datetime
from enum import Enum
from typing import Optional

from pydantic import BaseModel, ConfigDict, Field


class AffiliateStatus(str, Enum):
    active = "active"
    inactive = "inactive"
    expired = "expired"
    none = "none"


class ItemBase(BaseModel):
    name: str = Field(..., min_length=1, max_length=100)
    description: Optional[str] = Field(None, max_length=500)
    url: str = Field(..., min_length=10, max_length=2000)
    retailer: Optional[str] = Field(None, max_length=100)
    affiliate_url: Optional[str] = Field(None, max_length=2000)
    affiliate_status: Optional[AffiliateStatus] = None
    image_url: Optional[str] = Field(None, max_length=2000)
    price: Optional[float] = Field(None, gt=0)
    quantity_needed: int = Field(default=1, ge=1)
    quantity_purchased: int = Field(default=0, ge=0)
    is_active: bool = Field(default=True)


class ItemCreate(ItemBase):
    registry_id: int = Field(..., description="WP registry post ID this item belongs to")


class ItemRegistryCreate(ItemBase):
    """Item creation payload when registry_id comes from the URL path."""
    pass


class ItemUpdate(BaseModel):
    name: Optional[str] = Field(None, min_length=1, max_length=100)
    description: Optional[str] = Field(None, max_length=500)
    url: Optional[str] = Field(None, min_length=10, max_length=2000)
    retailer: Optional[str] = Field(None, max_length=100)
    affiliate_url: Optional[str] = Field(None, max_length=2000)
    affiliate_status: Optional[AffiliateStatus] = None
    image_url: Optional[str] = Field(None, max_length=2000)
    price: Optional[float] = Field(None, gt=0)
    quantity_needed: Optional[int] = Field(None, ge=1)
    quantity_purchased: Optional[int] = Field(None, ge=0)
    is_active: Optional[bool] = None


class Item(ItemBase):
    model_config = ConfigDict(from_attributes=True)

    id: int
    registry_id: int
    created_at: datetime
    updated_at: datetime


class ItemPublic(BaseModel):
    """Item fields visible to non-admin users — affiliate info excluded."""
    model_config = ConfigDict(from_attributes=True)

    id: int
    registry_id: int
    name: str
    description: Optional[str] = None
    url: str
    retailer: Optional[str] = None
    image_url: Optional[str] = None
    price: Optional[float] = None
    quantity_needed: int
    quantity_purchased: int
    is_active: bool
    created_at: datetime
    updated_at: datetime


class ItemResponse(BaseModel):
    success: bool
    data: Optional[Item] = None
    message: Optional[str] = None


class ItemPublicResponse(BaseModel):
    success: bool
    data: Optional[ItemPublic] = None
    message: Optional[str] = None

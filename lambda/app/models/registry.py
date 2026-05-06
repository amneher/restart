"""
Registry:
 user (owner)
 create_date
 updated
 invitees
 private/public
 items

WP 'restart-registry' custom post type:
{
    "restart-registry": {
        "name": "restart-registry",
        "label": "Registries",
        "singular_label": "Registry",
        "description": "Consists of a user\\'s story, info, and list of items they wished for.",
        "public": "true",
        "publicly_queryable": "true",
        "show_ui": "true",
        "show_in_nav_menus": "true",
        "delete_with_user": "false",
        "show_in_rest": "true",
        "rest_base": "restart-registry",
        "rest_controller_class": "",
        "rest_namespace": "",
        "has_archive": "false",
        "has_archive_string": "",
        "exclude_from_search": "false",
        "capability_type": "post",
        "hierarchical": "false",
        "can_export": "false",
        "rewrite": "true",
        "rewrite_slug": "",
        "rewrite_withfront": "true",
        "query_var": "true",
        "query_var_slug": "",
        "menu_position": "",
        "show_in_menu": "true",
        "show_in_menu_string": "",
        "menu_icon": null,
        "register_meta_box_cb": null,
        "supports": [
            "title",
            "editor",
            "thumbnail",
            "excerpt",
            "trackbacks",
            "custom-fields",
            "comments",
            "revisions",
            "author",
            "page-attributes",
            "post-formats",
        ],
        "taxonomies": ["category", "post_tag"],
        "labels": {
            "menu_name": "Restart Registry",
            "all_items": "All Registries",
            "add_new": "Create a Registry",
            "add_new_item": "Add Item to Registry",
            "edit_item": "Edit Registry",
            "new_item": "New Registry",
            "view_item": "View Registry",
            "view_items": "View Registry Items",
            "search_items": "Search Ites",
            "not_found": "No Registries Found",
            "featured_image": "Your beautiful mug",
            "set_featured_image": "Set Mugshot",
            "remove_featured_image": "Remove Mugshot",
            "use_featured_image": "Use as your glam pic",
            "archives": "Registry Archives",
            "not_found_in_trash": "",
            "parent_item_colon": "",
            "insert_into_item": "",
            "uploaded_to_this_item": "",
            "filter_items_list": "",
            "items_list_navigation": "",
            "items_list": "",
            "attributes": "",
            "name_admin_bar": "",
            "item_published": "",
            "item_published_privately": "",
            "item_reverted_to_draft": "",
            "item_trashed": "",
            "item_scheduled": "",
            "item_updated": "",
            "template_name": "",
        },
        "custom_supports": "",
        "enter_title_here": "My Headline",
    }
    'restart-registry': PostType(name='Registries', slug='restart-registry', description="Consists of a user's story, info, and list of items they wished for.", hierarchical=False, has_archive=False, rest_base='restart-registry', rest_namespace='wp/v2', taxonomies=['category', 'post_tag'], capabilities={}, labels={}, supports={}, icon=None, viewable=True, visibility={}),
}


"""

import json
from datetime import datetime
from typing import Any, Optional

from pydantic import BaseModel, ConfigDict, Field


# WP REST API path for the custom post type
REGISTRY_REST_PATH = "/wp/v2/restart-registry"

# Meta key prefix used for all registry-specific WP post meta
_META_PREFIX = "restart_"


class RegistryMeta(BaseModel):
    """Typed representation of WordPress post metadata for a registry.

    These fields are stored under the ``meta`` dict on the WP post and are
    retrieved/written via the REST API ``custom-fields`` support.

    Meta key naming convention: ``restart_<field>``.
    """

    # List of WP usernames allowed to view a private registry
    invitees: list[str] = Field(default_factory=list)

    # SQLite item IDs belonging to this registry (stored as a JSON array)
    item_ids: list[int] = Field(default_factory=list)

    # Optional event metadata
    event_type: Optional[str] = Field(default=None, max_length=100)
    event_date: Optional[str] = Field(default=None, description="ISO 8601 date string")

    def to_wp_meta(self) -> dict[str, Any]:
        """Serialize to the flat dict expected by the WP REST API meta field."""
        return {
            f"{_META_PREFIX}invitees": json.dumps(self.invitees),
            f"{_META_PREFIX}item_ids": json.dumps(self.item_ids),
            f"{_META_PREFIX}event_type": self.event_type or "",
            f"{_META_PREFIX}event_date": self.event_date or "",
        }

    @classmethod
    def from_wp_meta(cls, meta: dict[str, Any]) -> "RegistryMeta":
        """Deserialize from the flat dict returned by the WP REST API."""

        def _json_list(value: Any, cast) -> list:
            if isinstance(value, list):
                return [cast(v) for v in value]
            if isinstance(value, str) and value:
                try:
                    parsed = json.loads(value)
                    return [cast(v) for v in parsed]
                except (json.JSONDecodeError, TypeError):
                    pass
            return []

        return cls(
            invitees=_json_list(meta.get(f"{_META_PREFIX}invitees", []), str),
            item_ids=_json_list(meta.get(f"{_META_PREFIX}item_ids", []), int),
            event_type=meta.get(f"{_META_PREFIX}event_type") or None,
            event_date=meta.get(f"{_META_PREFIX}event_date") or None,
        )


class RegistryBase(BaseModel):
    # Registry display name → stored as the WP post title
    title: str = Field(..., min_length=1, max_length=200)

    # WP username of the registry owner (resolved to a WP user ID when writing)
    username: str = Field(..., min_length=1, max_length=100)

    # When True the WP post status is set to "private"; otherwise "publish"
    is_private: bool = Field(default=False)

    # User's personal narrative → stored as the WP post content
    story: Optional[str] = Field(default=None, max_length=2000)

    # Structured metadata stored in WP post meta
    meta: RegistryMeta = Field(default_factory=lambda: RegistryMeta())


class RegistryCreate(RegistryBase):
    pass


class RegistryUpdate(BaseModel):
    title: Optional[str] = Field(None, min_length=1, max_length=200)
    is_private: Optional[bool] = None
    story: Optional[str] = Field(None, max_length=2000)
    meta: Optional[RegistryMeta] = None


class Registry(RegistryBase):
    model_config = ConfigDict(from_attributes=True)

    # WP post ID
    id: int

    # Timestamps from the WP post
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None

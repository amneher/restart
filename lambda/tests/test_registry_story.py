"""Tests for the story field added to RegistryBase, RegistryUpdate, and registry routes.

QA review incorporated before writing — see inline comments for documented
behavioral quirks (empty-string create vs. update inconsistency, null-patch
no-op, cannot-clear-via-null).

Run order: model tests first (faster, failures invalidate route assumptions),
then deserialization, then create/update/list route tests.

e2e and wp_integration suites are deliberately excluded here — they require a
live WordPress instance and test the full storage layer, not the field logic.
"""

import base64
import os

os.environ.setdefault("DATABASE_PATH", ":memory:")

import pytest
from pydantic import ValidationError
from unittest.mock import MagicMock, patch
from fastapi.testclient import TestClient

from app.auth.models import WPUser
from app.main import app
from app.database import init_db, close_db
from app.models.registry import Registry, RegistryBase, RegistryCreate, RegistryMeta, RegistryUpdate


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
    content=None,   # omit key entirely when None — mirrors WP context=embed behaviour
) -> dict:
    post = {
        "id": id,
        "author": author,
        "title": {"rendered": title},
        "status": status,
        "date": "2026-04-01T10:00:00",
        "modified": "2026-04-01T12:00:00",
        "meta": meta or {},
    }
    if content is not None:
        post["content"] = content
    return post


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
    with patch("app.auth.dependencies.validate_credentials") as m:
        m.return_value = _wp_user()
        yield m


@pytest.fixture
def mock_wp():
    with patch("app.routes.registry.get_wp_client") as m:
        mock_client = MagicMock()
        mock_client.__enter__.return_value = mock_client
        m.return_value = mock_client
        yield mock_client


# ---------------------------------------------------------------------------
# Model tests — run first; failures here invalidate route-test assumptions
# ---------------------------------------------------------------------------

class TestRegistryStoryModel:
    def test_story_defaults_to_none(self):
        base = RegistryBase(title="My Registry", username="u")
        assert base.story is None

    def test_story_accepts_text(self):
        base = RegistryBase(title="My Registry", username="u", story="I started over.")
        assert base.story == "I started over."

    def test_story_exactly_2000_chars_valid(self):
        base = RegistryBase(title="My Registry", username="u", story="x" * 2000)
        assert len(base.story) == 2000

    def test_story_over_2000_chars_invalid(self):
        with pytest.raises(ValidationError):
            RegistryBase(title="My Registry", username="u", story="x" * 2001)

    def test_story_empty_string_accepted_by_model(self):
        # Pydantic has no min_length on story; "" is valid at the model layer.
        # Route-level create treats "" as falsy and omits the content key (documented below).
        base = RegistryBase(title="My Registry", username="u", story="")
        assert base.story == ""

    def test_registry_create_inherits_story(self):
        rc = RegistryCreate(title="My Registry", username="u", story="My story")
        assert rc.story == "My story"

    def test_registry_update_story_none_valid(self):
        ru = RegistryUpdate(story=None)
        assert ru.story is None

    def test_registry_update_story_set(self):
        ru = RegistryUpdate(story="Updated story")
        assert ru.story == "Updated story"

    def test_registry_update_story_empty_string_valid(self):
        # "" is valid in RegistryUpdate; route sends it to WP as content="" (see update tests).
        ru = RegistryUpdate(story="")
        assert ru.story == ""

    def test_registry_update_story_over_2000_invalid(self):
        with pytest.raises(ValidationError):
            RegistryUpdate(story="x" * 2001)

    def test_registry_response_model_round_trips_story(self):
        reg = Registry(
            id=1, title="T", username="u", is_private=False,
            story="My story",
            meta=RegistryMeta(),
        )
        dumped = reg.model_dump()
        assert dumped["story"] == "My story"

    def test_registry_response_model_story_none(self):
        reg = Registry(
            id=1, title="T", username="u", is_private=False,
            story=None,
            meta=RegistryMeta(),
        )
        assert reg.story is None


# ---------------------------------------------------------------------------
# Deserialization tests — _post_to_registry via GET /registries/{id}
# Run before create/update; deserialization bugs produce misleading failures there.
# ---------------------------------------------------------------------------

class TestStoryDeserialization:
    def test_raw_content_used(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={"raw": "My story", "rendered": "<p>Other</p>"}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        # raw wins over rendered when both present
        assert resp.json()["story"] == "My story"

    def test_rendered_used_when_raw_absent(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={"rendered": "<p>From rendered</p>"}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["story"] == "<p>From rendered</p>"

    def test_rendered_fallback_when_raw_empty(self, client, mock_auth, mock_wp):
        # WP returns raw="" when context=view and caller lacks edit_posts.
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={"raw": "", "rendered": "<p>Fallback</p>"}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["story"] == "<p>Fallback</p>"

    def test_empty_content_dict_returns_none(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["story"] is None

    def test_missing_content_key_returns_none(self, client, mock_auth, mock_wp):
        # _wp_post without content= omits the key entirely (context=embed)
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(author=1)
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["story"] is None

    def test_content_as_plain_string(self, client, mock_auth, mock_wp):
        # WP REST occasionally returns content as a bare string
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content="plain string"
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["story"] == "plain string"

    def test_content_none_returns_story_none(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content=None
        )
        # _wp_post does NOT omit the key when content=None is passed explicitly;
        # adjust the post dict to set "content": None after construction
        post = _wp_post(author=1)
        post["content"] = None
        mock_wp.custom_post_type.return_value.get.return_value = post
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.status_code == 200
        assert resp.json()["story"] is None

    def test_leading_trailing_whitespace_stripped(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={"raw": "  My story  "}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.json()["story"] == "My story"

    def test_whitespace_only_story_returns_none(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={"raw": "   "}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.json()["story"] is None

    def test_multiline_story_preserved(self, client, mock_auth, mock_wp):
        story = "Line 1\n\nLine 2"
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={"raw": story}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.json()["story"] == story

    def test_html_in_rendered_passes_through_unchanged(self, client, mock_auth, mock_wp):
        # story is not sanitized server-side — document this behaviour explicitly
        html = "<p>My <strong>story</strong></p>"
        mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
            author=1, content={"rendered": html}
        )
        resp = client.get("/registries/100", headers=_basic_auth())
        assert resp.json()["story"] == html


# ---------------------------------------------------------------------------
# Create tests
# ---------------------------------------------------------------------------

class TestCreateStory:
    def test_create_with_story_sends_content_to_wp(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.create.return_value = _wp_post(
            id=200, author=1, content={"raw": "My story"}
        )
        client.post(
            "/registries",
            json={"title": "Registry", "username": "testuser", "story": "My story"},
            headers=_basic_auth(),
        )
        call_kwargs = mock_wp.custom_post_type.return_value.create.call_args[0][0]
        assert call_kwargs.get("content") == "My story"

    def test_create_without_story_omits_content_key(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.create.return_value = _wp_post(
            id=201, author=1
        )
        client.post(
            "/registries",
            json={"title": "Registry", "username": "testuser"},
            headers=_basic_auth(),
        )
        call_kwargs = mock_wp.custom_post_type.return_value.create.call_args[0][0]
        assert "content" not in call_kwargs

    def test_create_with_story_response_includes_story(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.create.return_value = _wp_post(
            id=202, author=1, content={"raw": "My story"}
        )
        resp = client.post(
            "/registries",
            json={"title": "Registry", "username": "testuser", "story": "My story"},
            headers=_basic_auth(),
        )
        assert resp.status_code == 201
        assert resp.json()["story"] == "My story"

    def test_create_empty_string_story_omits_content_key(self, client, mock_auth, mock_wp):
        # Empty string is falsy — create treats it as "no story" and omits content.
        # This differs from update, which sends story="" through (documented in update tests).
        mock_wp.custom_post_type.return_value.create.return_value = _wp_post(
            id=203, author=1
        )
        client.post(
            "/registries",
            json={"title": "Registry", "username": "testuser", "story": ""},
            headers=_basic_auth(),
        )
        call_kwargs = mock_wp.custom_post_type.return_value.create.call_args[0][0]
        assert "content" not in call_kwargs

    def test_create_with_story_and_private_sends_both(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.create.return_value = _wp_post(
            id=204, author=1, status="private", content={"raw": "Private story"}
        )
        client.post(
            "/registries",
            json={"title": "Registry", "username": "testuser", "is_private": True, "story": "Private story"},
            headers=_basic_auth(),
        )
        call_kwargs = mock_wp.custom_post_type.return_value.create.call_args[0][0]
        assert call_kwargs.get("status") == "private"
        assert call_kwargs.get("content") == "Private story"

    def test_create_story_over_2000_chars_returns_422(self, client, mock_auth, mock_wp):
        resp = client.post(
            "/registries",
            json={"title": "Registry", "username": "testuser", "story": "x" * 2001},
            headers=_basic_auth(),
        )
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# Update tests
# ---------------------------------------------------------------------------

class TestUpdateStory:
    def test_patch_with_story_sends_content_to_wp(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1, content={"raw": "Updated story"})

        client.patch(
            "/registries/100",
            json={"story": "Updated story"},
            headers=_basic_auth(),
        )
        update_payload = cpt.update.call_args[0][1]
        assert update_payload.get("content") == "Updated story"

    def test_patch_without_story_omits_content(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1, title="New Title")

        client.patch(
            "/registries/100",
            json={"title": "New Title"},
            headers=_basic_auth(),
        )
        update_payload = cpt.update.call_args[0][1]
        assert "content" not in update_payload

    def test_patch_story_null_is_noop(self, client, mock_auth, mock_wp):
        # story=null and story omitted are indistinguishable at the Pydantic layer
        # (both set data.story=None). cpt.update must not be called with content.
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1, title="Original")

        resp = client.patch(
            "/registries/100",
            json={"story": None},
            headers=_basic_auth(),
        )
        assert resp.status_code == 200
        # No field to update → cpt.update should not be called at all
        cpt.update.assert_not_called()

    def test_patch_only_null_story_does_not_call_update(self, client, mock_auth, mock_wp):
        # Identical code path to above; explicit test for G19 — no payload → early return
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)

        client.patch("/registries/100", json={"story": None}, headers=_basic_auth())
        cpt.update.assert_not_called()

    def test_patch_empty_string_story_sends_content(self, client, mock_auth, mock_wp):
        # story="" is not None → content="" IS sent to WP.
        # This is the only way to clear a story via PATCH; story=null cannot do it.
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1)

        client.patch(
            "/registries/100",
            json={"story": ""},
            headers=_basic_auth(),
        )
        update_payload = cpt.update.call_args[0][1]
        assert update_payload.get("content") == ""

    def test_patch_story_with_title_sends_both(self, client, mock_auth, mock_wp):
        cpt = mock_wp.custom_post_type.return_value
        cpt.get.return_value = _wp_post(id=100, author=1)
        cpt.update.return_value = _wp_post(id=100, author=1, title="New", content={"raw": "New story"})

        client.patch(
            "/registries/100",
            json={"title": "New", "story": "New story"},
            headers=_basic_auth(),
        )
        update_payload = cpt.update.call_args[0][1]
        assert update_payload.get("title") == "New"
        assert update_payload.get("content") == "New story"

    def test_patch_story_forbidden_non_owner(self, client, mock_wp):
        with patch("app.auth.dependencies.validate_credentials") as m:
            m.return_value = _wp_user(id=5, username="stranger")
            mock_wp.custom_post_type.return_value.get.return_value = _wp_post(
                id=100, author=99
            )
            resp = client.patch(
                "/registries/100",
                json={"story": "sneaky"},
                headers=_basic_auth(),
            )
            assert resp.status_code == 403

    def test_patch_story_over_2000_chars_returns_422(self, client, mock_auth, mock_wp):
        resp = client.patch(
            "/registries/100",
            json={"story": "x" * 2001},
            headers=_basic_auth(),
        )
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# List tests — story surfaced through list[Registry] response model
# ---------------------------------------------------------------------------

class TestListStory:
    def test_list_returns_story_for_registries(self, client, mock_auth, mock_wp):
        mock_wp.custom_post_type.return_value.list.return_value = [
            _wp_post(id=1, author=1, title="Reg with story", content={"raw": "A story"}),
            _wp_post(id=2, author=1, title="Reg without story"),
        ]
        resp = client.get("/registries", headers=_basic_auth())
        assert resp.status_code == 200
        data = resp.json()
        assert data[0]["story"] == "A story"
        assert data[1]["story"] is None

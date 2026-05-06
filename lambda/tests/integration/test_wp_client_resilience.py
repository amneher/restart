"""Resilience and edge-case tests for WordPress credential validation.

test_auth.py already covers: successful validation, AuthenticationError,
Bearer tokens, invalid base64, connection refused (via get_wp_client side_effect),
and basic caching hit. This file covers the remaining gaps.
"""
import base64
import os

os.environ.setdefault("DATABASE_PATH", ":memory:")

import pytest
from unittest.mock import MagicMock, patch

from wp_python.exceptions import AuthenticationError, WordPressError

from app.auth.wp_client import _parse_basic_auth, clear_cache, validate_credentials


def _basic(username: str, password: str) -> str:
    creds = base64.b64encode(f"{username}:{password}".encode()).decode()
    return f"Basic {creds}"


def _make_wp_python_user(**overrides):
    from wp_python.models.user import User

    defaults = {
        "id": 1,
        "username": "testuser",
        "slug": "testuser",
        "email": "test@example.com",
        "name": "Test User",
        "roles": ["subscriber"],
        "capabilities": {},
    }
    defaults.update(overrides)
    return User.model_validate(defaults)


def _mock_wp_client(wp_user=None, side_effect=None):
    """Context-manager-compatible mock WordPress client."""
    mock = MagicMock()
    mock.__enter__.return_value = mock
    if side_effect:
        mock.users.me.side_effect = side_effect
    else:
        mock.users.me.return_value = wp_user
    return mock


@pytest.fixture(autouse=True)
def _clear_cache():
    clear_cache()
    yield
    clear_cache()


class TestParseBasicAuth:
    def test_valid_credentials(self):
        result = _parse_basic_auth(_basic("alice", "secret"))
        assert result == ("alice", "secret")

    def test_password_with_colons(self):
        # WP application passwords look like "abcd efgh ijkl" — colons possible too
        result = _parse_basic_auth(_basic("alice", "part1:part2:part3"))
        assert result == ("alice", "part1:part2:part3")

    def test_empty_password(self):
        result = _parse_basic_auth(_basic("alice", ""))
        assert result == ("alice", "")

    def test_empty_username(self):
        result = _parse_basic_auth(_basic("", "password"))
        assert result == ("", "password")

    def test_no_colon_in_decoded_value_returns_none(self):
        encoded = base64.b64encode(b"nocolon").decode()
        assert _parse_basic_auth(f"Basic {encoded}") is None

    def test_bearer_scheme_returns_none(self):
        assert _parse_basic_auth("Bearer some-jwt-token") is None

    def test_empty_string_returns_none(self):
        assert _parse_basic_auth("") is None

    def test_basic_with_no_credentials_returns_none(self):
        assert _parse_basic_auth("Basic ") is None


class TestValidateCredentialsResilience:
    @patch("app.auth.wp_client.get_wp_client")
    def test_wordpress_error_from_users_me_returns_none(self, mock_get):
        """WordPressError (non-auth, e.g. 500) from users.me → None."""
        mock_get.return_value = _mock_wp_client(
            side_effect=WordPressError("Internal Server Error", 500)
        )
        assert validate_credentials(_basic("u", "p")) is None

    @patch("app.auth.wp_client.get_wp_client")
    def test_read_timeout_returns_none(self, mock_get):
        """Network read timeout from users.me → None."""
        mock_get.return_value = _mock_wp_client(
            side_effect=Exception("Read timeout after 10s")
        )
        assert validate_credentials(_basic("u", "p")) is None

    @patch("app.auth.wp_client.get_wp_client")
    def test_connection_reset_returns_none(self, mock_get):
        """Connection reset by peer inside the context manager → None."""
        mock_get.return_value = _mock_wp_client(
            side_effect=Exception("Connection reset by peer")
        )
        assert validate_credentials(_basic("u", "p")) is None

    @patch("app.auth.wp_client.get_wp_client")
    def test_user_with_empty_slug_falls_back_to_username(self, mock_get):
        """wp_python user with empty slug (falsy) uses username field instead."""
        wp_user = _make_wp_python_user(slug="", username="noslug-user")
        mock_get.return_value = _mock_wp_client(wp_user=wp_user)

        user = validate_credentials(_basic("noslug-user", "pw"))
        assert user is not None
        assert user.username == "noslug-user"

    @patch("app.auth.wp_client.get_wp_client")
    def test_cache_ttl_expiry_triggers_revalidation(self, mock_get):
        """Once the TTL window passes, the next call re-hits WordPress."""
        wp_user = _make_wp_python_user()
        mock_get.return_value = _mock_wp_client(wp_user=wp_user)
        header = _basic("u", "p")

        with patch("app.auth.wp_client.time.time") as mock_time:
            mock_time.return_value = 1_000_000.0
            validate_credentials(header)
            assert mock_get.call_count == 1

            # Advance past the 5-minute (300 s) TTL
            mock_time.return_value = 1_000_000.0 + 301
            validate_credentials(header)
            assert mock_get.call_count == 2

    @patch("app.auth.wp_client.get_wp_client")
    def test_cache_hit_within_ttl_skips_wp(self, mock_get):
        """A call within TTL returns the cached user without touching WP."""
        wp_user = _make_wp_python_user()
        mock_get.return_value = _mock_wp_client(wp_user=wp_user)
        header = _basic("u", "p")

        with patch("app.auth.wp_client.time.time") as mock_time:
            mock_time.return_value = 1_000_000.0
            validate_credentials(header)

            mock_time.return_value = 1_000_000.0 + 60  # well within TTL
            result = validate_credentials(header)

        assert result is not None
        assert mock_get.call_count == 1  # WP called exactly once

    @patch("app.auth.wp_client.get_wp_client")
    def test_clear_cache_forces_revalidation(self, mock_get):
        """clear_cache() removes all entries; the next call re-hits WP."""
        wp_user = _make_wp_python_user()
        mock_get.return_value = _mock_wp_client(wp_user=wp_user)
        header = _basic("u", "p")

        validate_credentials(header)
        assert mock_get.call_count == 1

        clear_cache()
        validate_credentials(header)
        assert mock_get.call_count == 2

    @patch("app.auth.wp_client.get_wp_client")
    def test_different_credentials_cached_independently(self, mock_get):
        """Two users share no cache slot; each is validated independently."""
        user_a = _make_wp_python_user(id=1, slug="alice", email="a@e.com", name="Alice")
        user_b = _make_wp_python_user(id=2, slug="bob", email="b@e.com", name="Bob")

        mock_get.side_effect = [
            _mock_wp_client(wp_user=user_a),
            _mock_wp_client(wp_user=user_b),
        ]

        result_a = validate_credentials(_basic("alice", "pw1"))
        result_b = validate_credentials(_basic("bob", "pw2"))
        assert result_a.id == 1
        assert result_b.id == 2
        assert mock_get.call_count == 2

        # Both are now cached; no further WP calls
        validate_credentials(_basic("alice", "pw1"))
        validate_credentials(_basic("bob", "pw2"))
        assert mock_get.call_count == 2

    @patch("app.auth.wp_client.get_wp_client")
    def test_failed_validation_not_cached(self, mock_get):
        """None results are not stored in the cache; each failure re-hits WP."""
        mock_get.return_value = _mock_wp_client(
            side_effect=AuthenticationError("bad creds", 401)
        )
        header = _basic("u", "wrong")
        validate_credentials(header)
        validate_credentials(header)
        assert mock_get.call_count == 2

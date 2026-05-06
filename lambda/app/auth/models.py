"""WordPress user model returned after successful authentication."""

from pydantic import BaseModel


class WPUser(BaseModel):
    """Authenticated WordPress user context attached to requests."""

    id: int
    username: str
    email: str
    display_name: str
    roles: list[str]
    capabilities: dict[str, bool] = {}

    def has_role(self, role: str) -> bool:
        return role in self.roles

    def has_capability(self, cap: str) -> bool:
        return self.capabilities.get(cap, False)

    @property
    def is_admin(self) -> bool:
        return "administrator" in self.roles

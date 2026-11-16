"""Optional MCP server exposing the lab's tools over the Model Context Protocol.

The agents can use these instead of calling tools.py directly:

    python main.py --mcp

main.py starts this file as a subprocess and talks to it over stdio (see
mcp_toolbox.py). It can also be attached to any other MCP client:

    python mcp_server.py
"""

from __future__ import annotations

from contextlib import contextmanager

try:  # mcp >= 2.0
    from mcp.server.mcpserver import MCPServer
    from mcp.server.mcpserver.exceptions import ToolError as MCPToolError
except ImportError:  # mcp 1.x
    from mcp.server.fastmcp import FastMCP as MCPServer
    from mcp.server.fastmcp.exceptions import ToolError as MCPToolError

import tools

server = MCPServer("company-tools", log_level="ERROR")


@contextmanager
def reported_to_client():
    """Turn a tool failure into an MCP tool error so its message reaches the agent."""
    try:
        yield
    except tools.ToolError as error:
        raise MCPToolError(str(error)) from None


@server.tool(description=tools.DESCRIPTIONS["search_knowledge_base"])
def search_knowledge_base(query: str, top_k: int = 5) -> str:
    with reported_to_client():
        return tools.search_knowledge_base(query, top_k)


@server.tool(description=tools.DESCRIPTIONS["lookup_contact"])
def lookup_contact(name: str) -> str:
    with reported_to_client():
        return tools.lookup_contact(name)


@server.tool(description=tools.DESCRIPTIONS["send_email"])
def send_email(to: str, subject: str, body: str) -> str:
    with reported_to_client():
        return tools.send_email(to, subject, body)


@server.tool(description=tools.DESCRIPTIONS["count_words"])
def count_words(text: str) -> str:
    return tools.count_words(text)


if __name__ == "__main__":
    server.run()  # stdio transport

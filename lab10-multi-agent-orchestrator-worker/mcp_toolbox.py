"""MCP client side: the same toolbox interface as LocalToolbox, served by mcp_server.py.

The agents are synchronous and the MCP SDK is async, so the MCP session lives on
a background event loop and each tool call is submitted to it and awaited.
"""

from __future__ import annotations

import asyncio
import os
import sys
import threading
from pathlib import Path
from typing import Any

from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client

from tools import Tool, ToolError

SERVER_SCRIPT = Path(__file__).resolve().parent / "mcp_server.py"


def _field(obj: Any, snake: str, camel: str) -> Any:
    """Read a field that is snake_case in mcp 2.x and camelCase in mcp 1.x."""
    return getattr(obj, snake) if hasattr(obj, snake) else getattr(obj, camel)


class MCPToolbox:
    """Hands out tools whose handlers call the MCP server."""

    def __init__(self, server_script: Path = SERVER_SCRIPT) -> None:
        self._server_script = server_script
        self._loop = asyncio.new_event_loop()
        self._session: ClientSession | None = None
        self._tools: dict[str, Any] = {}
        self._ready = threading.Event()
        self._stop: asyncio.Event | None = None
        self._startup_error: BaseException | None = None
        self._thread = threading.Thread(target=self._loop.run_forever, daemon=True)
        self._thread.start()
        self._main = asyncio.run_coroutine_threadsafe(self._serve(), self._loop)
        self._ready.wait(timeout=30)
        if self._startup_error or self._session is None:
            raise RuntimeError(f"Could not start the MCP server: {self._startup_error}")

    async def _serve(self) -> None:
        """Open the stdio connection and hold it until close() is called."""
        self._stop = asyncio.Event()
        params = StdioServerParameters(
            command=sys.executable,
            args=[str(self._server_script)],
            cwd=str(self._server_script.parent),
            env=dict(os.environ),
        )
        try:
            async with stdio_client(params) as (read, write):
                async with ClientSession(read, write) as session:
                    await session.initialize()
                    listed = await session.list_tools()
                    self._tools = {tool.name: tool for tool in listed.tools}
                    self._session = session
                    self._ready.set()
                    await self._stop.wait()
        except BaseException as error:  # surface startup failures to __init__
            self._startup_error = error
            self._ready.set()
            raise

    def _call(self, name: str, arguments: dict[str, Any]) -> str:
        assert self._session is not None
        future = asyncio.run_coroutine_threadsafe(
            self._session.call_tool(name, arguments), self._loop
        )
        result = future.result(timeout=60)
        text = "\n".join(block.text for block in result.content if block.type == "text")
        if _field(result, "is_error", "isError"):
            raise ToolError(text.removeprefix(f"Error executing tool {name}: "))
        return text

    def get(self, names: list[str]) -> list[Tool]:
        tools = []
        for name in names:
            spec = self._tools[name]
            tools.append(
                Tool(
                    name=name,
                    description=spec.description or "",
                    input_schema=_field(spec, "input_schema", "inputSchema"),
                    handler=lambda _name=name, **arguments: self._call(_name, arguments),
                )
            )
        return tools

    def close(self) -> None:
        if self._stop is not None:
            self._loop.call_soon_threadsafe(self._stop.set)
            try:
                self._main.result(timeout=10)
            except Exception:
                pass
        self._loop.call_soon_threadsafe(self._loop.stop)
        self._thread.join(timeout=5)

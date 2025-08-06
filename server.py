from fastapi import FastAPI, WebSocket, WebSocketDisconnect
import asyncio
import json
from typing import Dict, Set

app = FastAPI()

clients: Dict[WebSocket, str] = {}
stream_subscribers: Set[WebSocket] = set()
stream_producers: Dict[str, WebSocket] = {}

@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    await websocket.accept()
    try:
        message = await websocket.receive_text()
        clients[websocket] = message

        if message.startswith("STREAMER:"):
            streamer_name = message.split(":", 1)[1]
            stream_producers[streamer_name] = websocket
            print(f"[STREAMER CONNECTED] {streamer_name}")
            await broadcast_pc_list()

            while True:
                try:
                    data = await websocket.receive_bytes()
                    await broadcast_to_clients(data)
                except WebSocketDisconnect:
                    print(f"[STREAMER DISCONNECTED] {streamer_name}")
                    break
                except Exception as e:
                    print(f"[ERROR receiving from streamer] {e}")
                    break

            # Cleanup
            if streamer_name in stream_producers:
                del stream_producers[streamer_name]
                await broadcast_pc_list()

        elif message == "CLIENT":
            stream_subscribers.add(websocket)
            print("[CLIENT CONNECTED]")
            await send_pc_list(websocket)

            while True:
                try:
                    msg = await websocket.receive_text()
                    if msg == "get_pcs":
                        await send_pc_list(websocket)
                except WebSocketDisconnect:
                    print("[CLIENT DISCONNECTED]")
                    break
                except Exception as e:
                    print(f"[ERROR receiving from client] {e}")
                    break

        # Cleanup common to all
        clients.pop(websocket, None)
        stream_subscribers.discard(websocket)

    except WebSocketDisconnect:
        print("[DISCONNECTED BEFORE INIT]")
        clients.pop(websocket, None)
        stream_subscribers.discard(websocket)

async def broadcast_to_clients(data: bytes):
    dead_clients = []
    for client_ws in stream_subscribers:
        try:
            await client_ws.send_bytes(data)
        except Exception as e:
            print(f"[ERROR sending to client] {e}")
            dead_clients.append(client_ws)

    for dead in dead_clients:
        stream_subscribers.discard(dead)
        clients.pop(dead, None)

async def broadcast_pc_list():
    if not stream_subscribers:
        return
    message = json.dumps({
        "type": "pc_list",
        "pcs": list(stream_producers.keys())
    })
    for client in list(stream_subscribers):
        try:
            await client.send_text(message)
        except Exception as e:
            print(f"[ERROR sending pc list] {e}")
            stream_subscribers.discard(client)
            clients.pop(client, None)

async def send_pc_list(websocket: WebSocket):
    message = json.dumps({
        "type": "pc_list",
        "pcs": list(stream_producers.keys())
    })
    try:
        await websocket.send_text(message)
    except Exception as e:
        print(f"[ERROR sending pc list to one client] {e}")
        stream_subscribers.discard(websocket)
        clients.pop(websocket, None)

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8080)
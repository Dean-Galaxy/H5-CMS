"""Loopback listener for the exact Nginx-proxied Resend webhook path."""
from wsgiref.simple_server import WSGIRequestHandler, WSGIServer, make_server

from resend_feedback import application


class FeedbackServer(WSGIServer):
    request_queue_size = 32

    def get_request(self):
        connection, address = super().get_request()
        connection.settimeout(6)
        return connection, address


class QuietHandler(WSGIRequestHandler):
    def log_message(self, format, *args):
        pass


if __name__ == '__main__':
    with make_server('127.0.0.1', 18746, application, FeedbackServer, QuietHandler) as server:
        server.serve_forever()

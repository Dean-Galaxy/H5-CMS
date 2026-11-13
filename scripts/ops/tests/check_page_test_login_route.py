#!/usr/bin/env python3
"""Explicit staging-only entry preflight. Prints path/status, never credentials or page data."""
import argparse
from html.parser import HTMLParser
from http.cookiejar import CookieJar
import json
import re
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, ProxyHandler, Request

ORIGIN = 'https://staging.sparklingshort.com'


class ScriptUpdateURI(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = set()

    def handle_starttag(self, tag, attrs):
        if tag == 'script':
            value = dict(attrs).get('data-update-uri')
            if value:
                self.values.add(value)


class RejectRedirects(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def update_url(html):
    page = ScriptUpdateURI()
    page.feed(html)
    if len(page.values) != 1:
        raise ValueError('UPDATE_URI_NOT_UNIQUE')
    url = urljoin(ORIGIN, next(iter(page.values)))
    parsed = urlsplit(url)
    if (parsed.scheme, parsed.netloc) != ('https', 'staging.sparklingshort.com'):
        raise ValueError('UPDATE_ORIGIN_INVALID')
    if parsed.query or parsed.fragment or not re.fullmatch(
        r'/(?:admin/livewire|livewire-[a-f0-9]{8})/update', parsed.path
    ):
        raise ValueError('UPDATE_PATH_INVALID')
    return url


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--login-url', required=True)
    args = parser.parse_args()
    path = '/admin/login'
    status = None
    try:
        if args.login_url != ORIGIN + path:
            raise ValueError('LOGIN_URL_INVALID')
        opener = build_opener(ProxyHandler({}), RejectRedirects(), HTTPCookieProcessor(CookieJar()))
        with opener.open(Request(args.login_url, headers={'Accept': 'text/html'}), timeout=15) as response:
            status = response.status
            html = response.read(1048577)
        if status != 200 or len(html) > 1048576:
            raise ValueError('LOGIN_PAGE_INVALID')
        url = update_url(html.decode('utf-8'))
        path = urlsplit(url).path
        request = Request(url, data=b'{}', method='POST', headers={
            'Content-Type': 'application/json', 'Accept': 'application/json', 'Origin': ORIGIN,
        })
        try:
            with opener.open(request, timeout=15) as response:
                status = response.status
        except HTTPError as error:
            status = error.code
            error.close()
        print(json.dumps({'path': path, 'status': status}))
        return 0 if status == 419 else 1
    except HTTPError as error:
        status = error.code
        error.close()
        print(json.dumps({'path': path, 'status': status}))
        return 1
    except (OSError, URLError, ValueError):
        print(json.dumps({'path': path, 'status': status}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())

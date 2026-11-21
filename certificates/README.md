# Default CA trust bundle

`cacert.pem` is the Mozilla CA root bundle distributed by curl:

- Source: https://curl.se/ca/cacert.pem
- Upstream documentation: https://curl.se/docs/caextract.html
- Downloaded: 2026-11-21
- Mozilla extraction date (bundle header): 2026-11-05
- SHA-256 of the distributed file: `427c064216761a1e72a8b30f0d954d08cb6e4435a82091fb865afd20c8497285`

The upstream header is preserved. Mozilla's certificate-data licensing is
MPL-2.0; see `LICENSE-MPL-2.0.txt`. Individual root certificates retain their
upstream ownership. This third-party trust bundle is not authored by this project.

The extension first checks readable PHP `openssl.cafile` / `curl.cainfo`
settings, then uses this bundle. It never downloads certificates during a
connection test, disables verification or edits global PHP/MySQL settings.
An empty CA input selects the default again.

This is a real public trust bundle, **not a server-specific certificate**.
Private/self-signed database certificates need the hosting provider's verified
CA file supplied as a custom local path. A bundle does not repair expired
certificates or a hostname mismatch.

Maintainers must periodically refresh this file from the HTTPS upstream, review
its provenance/content and update the recorded hash/date. Do not replace it with
a certificate supplied by an untrusted database endpoint.

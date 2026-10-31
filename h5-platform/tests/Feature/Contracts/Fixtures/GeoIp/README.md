# Synthetic MMDB fixture

Exact upstream commit `d1551916cff0712d12ff602c309234471b0402a2` from [MaxMind-DB](https://github.com/maxmind/MaxMind-DB/tree/d1551916cff0712d12ff602c309234471b0402a2), path `test-data/GeoIP2-Country-Test.mmdb`. The upstream README identifies this repository as format/test databases and permits Apache-2.0 or MIT; Apache option is selected, full LICENSE-APACHE included. File bytes/metadata/SHA256 in source.json were independently read using maxmind-db/reader 1.14.0.

17,300 bytes, synthetic country data only. Known test entries are public IPv4→GB and IPv6→JP; the IPv4 registered_country is US to verify it is never substituted for country.iso_code. Not a production DB-IP database, not an estimate of real IP accuracy or region authorization.

Tests copy this immutable fixture to a private temporary directory, set mode0444 and a test clock relative to metadata.buildEpoch. Equal-length metadata/ISO payload faults are made only in temporary copies and verified via the installed Reader; no decoder is implemented. Fixture/source/license are public; no actual visitor or app data. The real DB-IP Lite license/attribution is separate and remains a deployment requirement.

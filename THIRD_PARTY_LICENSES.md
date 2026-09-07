# Third-party licenses

Wallos ships data and code derived from third-party sources. Their licenses and
attributions are collected here.

---

## Unicode CLDR (Common Locale Data Repository)

`data/currencies/*.json` and `data/currencies/metadata.json` are generated from
the Unicode CLDR by `scripts/update_cldr_currencies.php`. Each file contains, per
ISO 4217 currency code, the localized display name and symbol for one language,
extracted from the CLDR-JSON `cldr-numbers-full` currency data.

- **Source:** Unicode CLDR — https://github.com/unicode-org/cldr-json
- **Pinned release:** CLDR-JSON `48.2.1` (recorded in `data/currencies/metadata.json`)
- **Copyright:** © Unicode, Inc.
- **License:** Unicode License v3 — `SPDX-License-Identifier: Unicode-3.0`

The generated files are a minimized derivative: only the `name` and `symbol`
fields are kept, keyed by currency code. The full CLDR repository is not
vendored. Regenerating against the same pinned release reproduces the files
byte for byte.

### Unicode License v3

```
UNICODE LICENSE V3

COPYRIGHT AND PERMISSION NOTICE

Copyright © 2015-2024 Unicode, Inc.

NOTICE TO USER: Carefully read the following legal agreement. BY
DOWNLOADING, INSTALLING, COPYING OR OTHERWISE USING DATA FILES, AND/OR
SOFTWARE, YOU UNEQUIVOCALLY ACCEPT, AND AGREE TO BE BOUND BY, ALL OF THE
TERMS AND CONDITIONS OF THIS AGREEMENT. IF YOU DO NOT AGREE, DO NOT
DOWNLOAD, INSTALL, COPY, DISTRIBUTE OR USE THE DATA FILES OR SOFTWARE.

Permission is hereby granted, free of charge, to any person obtaining a
copy of data files and any associated documentation (the "Data Files") or
software and any associated documentation (the "Software") to deal in the
Data Files or Software without restriction, including without limitation
the rights to use, copy, modify, merge, publish, distribute, and/or sell
copies of the Data Files or Software, and to permit persons to whom the
Data Files or Software are furnished to do so, provided that either (a)
this copyright and permission notice appear with all copies of the Data
Files or Software, or (b) this copyright and permission notice appear in
associated Documentation.

THE DATA FILES AND SOFTWARE ARE PROVIDED "AS IS", WITHOUT WARRANTY OF ANY
KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT OF
THIRD PARTY RIGHTS.

IN NO EVENT SHALL THE COPYRIGHT HOLDER OR HOLDERS INCLUDED IN THIS NOTICE
BE LIABLE FOR ANY CLAIM, OR ANY SPECIAL INDIRECT OR CONSEQUENTIAL DAMAGES,
OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS,
WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION,
ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THE DATA
FILES OR SOFTWARE.

Except as contained in this notice, the name of a copyright holder shall
not be used in advertising or otherwise to promote the sale, use or other
dealings in these Data Files or Software without prior written
authorization of the copyright holder.

SPDX-License-Identifier: Unicode-3.0
```

---

## ApexCharts

`scripts/libs/apexcharts.min.js` is the vendored, unmodified `dist/apexcharts.min.js`
of the ApexCharts npm package. The statistics page renders every chart with it.

- **Source:** ApexCharts — https://github.com/apexcharts/apexcharts.js
- **Pinned release:** `4.7.0` — the last release published under the MIT licence
- **Copyright:** © 2018 ApexCharts
- **License:** MIT — `SPDX-License-Identifier: MIT`
- **Provenance:** npm `apexcharts@4.7.0`, tarball verified against the registry
  integrity hash `sha512-iZSrrBGvVlL+nt2B1NpqfDuBZ9jX61X9I2+XV0hlYXHtTwhwLTHDKGXjNXAgFBDLuvSYCB/rq2nPWVPRv2DrGA==`

Wallos is GPL-3.0. ApexCharts changed its licensing model with the 5.x line, and
5.x is therefore not distributed here — the version is pinned rather than merely
current (issue #171). Anything that upgrades this file must check the licence of
the release it brings in; a newer version is not automatically a permitted one.
The charts use no 5.x-only functionality. One call had to be written so that it
works on both lines: the donut legend toggle updates options and series
separately, because passing a shortened series to `updateOptions` leaves the
removed slice on screen in 4.x.

### MIT License text as distributed with ApexCharts 4.7.0

```
The MIT License (MIT)

Copyright (c) 2018 ApexCharts

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

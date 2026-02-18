# VisitorExclusion

Block visits at the tracker level using configurable field-based rules. Visits matching a rule are silently dropped — the visitor receives a normal 200 response but no data is recorded.

> **Warning**
>
> This plugin is experimental and was coded using [Claude Code](https://claude.ai).
> It is provided without any warranty regarding quality, stability, or performance.
> This is a community project and is not officially supported by Matomo.

## Description

VisitorExclusion lets super-administrators define exclusion rules that are evaluated on every incoming tracking request. When a visit matches a rule, it is silently dropped at tracker time — permanently excluded before any data is written to the database.

Each rule is composed of one or more conditions on fields available at tracking time:

| Field | Description |
|-------|-------------|
| IP Address | Single IP or CIDR range (e.g. `192.168.0.0/16`) |
| User Agent | Browser/bot UA string (e.g. `Googlebot`, `AhrefsBot`) |
| Page URL | The tracked page URL |
| Referrer URL | The referring page URL |
| Device Type | `bot`, `smartphone`, `tablet`, `desktop`, `tv`… |
| Browser | `Chrome`, `Firefox`, `Safari`, `Edge`… |
| Operating System | `Windows`, `macOS`, `Linux`, `iOS`, `Android`… |
| Browser Language | Two-letter ISO code (e.g. `fr`, `en`, `de`) |
| Custom Dimension 1–20 | Value passed in the tracking request |

### Available operators

`equals`, `not equals`, `contains`, `does not contain`, `starts with`, `ends with`, `matches regex`, `does not match regex`, `is in IP/CIDR range`, `is not in IP/CIDR range`

### Match modes

- **AND** — all conditions must match (default)
- **OR** — any condition must match

### Common use cases

| Goal | Field | Operator | Value |
|------|-------|----------|-------|
| Block internal office traffic | IP Address | is in IP/CIDR range | `192.168.0.0/16` |
| Block search engine crawlers | User Agent | matches regex | `Googlebot\|bingbot\|YandexBot` |
| Block all bots | Device Type | equals | `bot` |
| Block a specific language | Browser Language | equals | `fr` |
| Block staging URLs | Page URL | starts with | `https://staging.` |

## Requirements

- Matomo >= 5.0
- PHP >= 8.1

## Installation

### From Matomo Marketplace
1. Go to **Administration > Marketplace**
2. Search for **VisitorExclusion**
3. Click **Install**

### Manual Installation
1. Download the latest release from GitHub
2. Extract into your `matomo/plugins/` directory as `VisitorExclusion/`
3. Activate the plugin in **Administration > Plugins**

## Configuration

Navigate to **Administration > System > Visitor Exclusion Rules** (super-admin only).

- **Add a rule** using the form at the bottom of the page
- **Enable / disable** individual rules without deleting them
- **Edit** a rule by clicking the Edit button in the table

> ⚠️ Exclusion rules are applied at tracker time. Dropped visits are permanently lost and cannot be recovered.

## License

GPL v3+. See [LICENSE](LICENSE) for details.

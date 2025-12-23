# NB Chain Link - Plugin Specification v1.1.0

## Concept
Modern Web Rings - distributed link chains where sites link to each other. Each ring has a HOST site that maintains the master member list. No central server needed.

## Ring Types
1. **Open** - Anyone joins instantly
2. **Moderated** - Host approves each member
3. **Private** - Invite code required
4. **Curated** - Host manually adds URLs (linked sites don't need plugin)

## How It Works
- Site A creates "Chess Ring" → A is the HOST
- Site B wants to join → enters A's URL + ring ID
- B's plugin calls A's REST API
- A approves (or auto-approves for open rings)
- B syncs member list from A periodically
- Hourly health checks mark dead sites, skip them in navigation

## Widget Display Options (v1.1.0 - TO BE BUILT)

### Navigation Mode
- **Carousel** - Prev/Next preview members, click to visit (default)
- **Live Links** - Prev/Next immediately navigate to that site
- **Directory/Menu** - Show all members as a list

### Theme
- **Light** - White background, dark text
- **Dark** - Dark background, light text

### Width
- **Compact** - Minimum width (350px)
- **Full** - 100% width

### Star Ratings
- Members rate other members (1-5 stars)
- Average displayed on directory
- Only plugin users can rate (verified via API ping)

## Shortcode
Base: `[nb_chain_link ring="ring-id"]`

With overrides: `[nb_chain_link ring="ring-id" mode="carousel" theme="dark" width="full"]`

Admin sets defaults, shortcode can override for multiple placements.

## Widget Design
```
┌─────────────────────────────────────┐
│  ◀   [Banner Image 200x80]      ▶  │
│        Site Name                    │
│        Short description...         │
│        ⭐⭐⭐⭐☆ (4.2)              │
├─────────────────────────────────────┤
│    [🎲 Random]  [Visit →]  [Join]   │
│          Ring Name                  │
└─────────────────────────────────────┘
```
- 1px black border, shadow
- Join button only if not member & ring allows
- Visit button for clarity

## Data Structure

### My Site Info (shared when joining rings)
```php
'name' => 'Site Name',
'url' => 'https://mysite.com',
'page_url' => 'https://mysite.com/links', // where widget displays
'image' => 'https://mysite.com/banner.png',
'excerpt' => 'Short description'
```

### Hosted Ring
```php
'ring-id' => [
    'name' => 'Ring Display Name',
    'type' => 'open|moderated|private|curated',
    'secret' => 'invite-code-for-private',
    'members' => [
        ['url', 'name', 'page_url', 'image', 'excerpt', 'joined', 'status', 'fails', 'ratings' => []]
    ],
    'pending' => [...],
    'created' => timestamp,
    'updated' => timestamp
]
```

### Joined Ring
```php
'ring-id' => [
    'host_url' => 'https://hostsite.com/',
    'ring_id' => 'ring-id',
    'secret' => '',
    'members' => [...], // synced from host
    'last_sync' => timestamp,
    'pending' => bool
]
```

## REST API Endpoints

### GET /wp-json/nb-chain-link/v1/ring/{ring_id}
Returns ring data (members list). Private rings require `?secret=xxx`

### POST /wp-json/nb-chain-link/v1/ring/{ring_id}/join
Body: url, name, page_url, image, excerpt, secret
Returns: {status: 'approved'|'pending'|'already_member'}

### GET /wp-json/nb-chain-link/v1/ping
Returns: {status: 'ok', version: '1.1.0'} - for health checks

### POST /wp-json/nb-chain-link/v1/ring/{ring_id}/rate (TO ADD)
Body: target_url, rating (1-5), rater_url
Verified via ping to rater's site

## Admin Page Sections

1. **My Site Info** - Name, page URL, banner image, description
2. **Create New Ring** - ID, name, type selection
3. **Join a Ring** - Host URL, ring ID, invite code
4. **Rings I Host** - List with pending approvals, member management, shortcode
5. **Rings I've Joined** - List with sync status, leave option
6. **Widget Settings** (NEW) - Default mode, theme, width

## Health Check Cron
- Runs hourly
- Pings 3 random members per hosted ring
- 3 consecutive fails = mark as 'dead' (skipped in widget)
- Recovery when ping succeeds again
- Also syncs all joined rings

## Files
- `/nb-chain-link/nb-chain-link.php` - Main plugin (DELETED - needs rebuild)

## To Build in v1.1.0
1. Curated ring type (manual member add, no API needed)
2. Widget display options (carousel/live/directory)
3. Theme option (light/dark)
4. Width option (compact/full)
5. Star rating system
6. Directory shortcode `[nb_chain_link_directory ring="x"]`
7. Visit button in widget
8. Admin defaults section

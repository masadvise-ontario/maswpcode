# CLAUDE.md

@/home/brian/workspace/development/klaus/.claude/home/protocols/security-preamble.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Maswpcode is a custom WordPress plugin that provides form processing functionality for Elementor forms and login redirection for private pages. The plugin integrates with CiviCRM to handle form submissions through both direct API calls and custom submission storage.

## Architecture

### Core Components

- **Main Plugin File**: `maswpcode.php` - Registers the Elementor form action and login redirect functionality
- **Form Processor**: `form-actions/Mas_Form_Processor.php` - Custom Elementor form action that processes submissions

### Form Processing Logic

The plugin handles two types of form submissions based on form ID:

1. **Submission Forms** (form ID starts with "sub_"): Data is stored in CiviCRM's MascodeSubmission entity using API4
2. **Processing Forms** (other form IDs): Data is processed through CiviCRM's FormProcessor API using API3

> **Known, accepted exception to the API4-only rule.** Brian's global convention is CiviCRM API4
> only — never API3, BAO, or DAO. The FormProcessor path here is a **deliberate exception**: this
> plugin drives Elementor Pro forms, and Elementor's FormProcessor integration only supported API3
> at the time it was built. Confirmed by Brian 2026-08-07 during `/doc-review`.
>
> **So do not "fix" this to API4.** It is working code, not drift. If Elementor gains API4 support
> the migration becomes worth considering — until then, leave it. Recorded here because the silence
> was the actual problem: a reader comparing this against the API4-only rule had no way to tell an
> accepted exception from an oversight.

### Key Features

- **Elementor Integration**: Extends Elementor Pro forms with custom form actions
- **CiviCRM Integration**: Uses both API3 (FormProcessor — accepted Elementor-era exception, see above) and API4 (MascodeSubmission)
- **Private Page Protection**: Automatically redirects non-logged-in users from private pages to login
- **Error Logging**: Comprehensive error logging for debugging form submissions

## Development Commands

### CiviCRM Cache Management
```bash
# Clear CiviCRM cache after making changes
/home/brian/buildkit/bin/cv flush
```

### Debugging and Logging
```bash
# Enable Xdebug for form processing
XDEBUG_SESSION=1 /home/brian/buildkit/bin/cv scr <script> --user=admin

# Check WordPress debug logs (actual location)
tail -f /home/brian/log/wordpress.logs/wp-debug.log

# Check server error logs
tail -f /var/log/apache2/error.log
```

### WordPress Database Queries
```bash
# Use direct MySQL queries instead of API4 for WordPress data
/home/brian/buildkit/bin/cv ev "global \$wpdb; echo json_encode(\$wpdb->get_results(\"SELECT * FROM wp_posts WHERE post_name = 'page_slug'\"));"

# Check if private pages exist
/home/brian/buildkit/bin/cv ev "global \$wpdb; \$page = \$wpdb->get_row(\$wpdb->prepare(\"SELECT post_name, post_status FROM wp_posts WHERE post_name = %s AND post_type = 'page'\", 'vcportal')); echo json_encode(\$page);"
```

## CiviCRM Dependencies

The plugin requires:
- **CiviCRM Extension**: MASCode extension with MascodeSubmission entity
- **FormProcessor Extension**: For API3 form processing functionality
- **API Access**: Both API3 and API4 access to CiviCRM

## WPO365 Microsoft OAuth Integration

The plugin includes custom login screen integration with WPO365:

### OAuth Configuration
- **WPO365 Plugin**: `wpo365-login` must be active and configured
- **OAuth URL**: `home_url('/?action=openidredirect&redirect_to=' . urlencode($target_url))`
- **Auth Scenario**: Set to 'internet' in WPO365 options
- **Azure Redirect URIs**: Must include `https://masdemo.localhost/` in Azure app registration

### Custom Login Features
- **Microsoft Sign-in Button**: Prominent OAuth button with Microsoft branding
- **Custom Instructions**: Shows masadvise.org email format and contact info
- **WordPress Fallback**: Collapsible username/password login option
- **Auto-Redirect**: Private pages redirect unauthenticated users to custom login

### Debugging OAuth Issues
```bash
# Enable WPO365 debug logging
/home/brian/buildkit/bin/cv ev "\$options = get_option('wpo365_options'); \$options['debug_log'] = true; update_option('wpo365_options', \$options);"

# Check OAuth flow in debug log
tail -f /home/brian/log/wordpress.logs/wp-debug.log | grep "Wpo\\"
```

## Important Notes

- Form IDs determine processing method: prefix "sub_" for submissions, others for form processing
- All form data is logged for debugging purposes
- Plugin depends on Elementor Pro for form functionality
- Private page redirection works only on front-end, not admin pages
- **Debug Log Location**: `/home/brian/log/wordpress.logs/wp-debug.log` (not in wp-content)
- **Database Queries**: Use `$wpdb` directly for WordPress data, not CiviCRM API4
- **OAuth Success**: Look for "Successfully saved user object id" and final redirect in debug log

## Production Access (Safe Inspection)

**Follow the shared protocol:** `/home/brian/workspace/development/klaus/.claude/home/protocols/production-access.md` — deliberately in the *private* klaus repo, not here, because it names the prod database, the privileged DB user, the SSH host alias, and the specific tables worth querying (including this plugin's Elementor submission tables), and **this repo is public**. It covers SSH-tunnel readonly inspection, the per-turn prod-write approval rule, and the hard rules.

maswpcode-specific notes:
- Inspect a public Elementor form's state without submitting:

```javascript
browser_navigate('https://www.masadvise.org/some-form-page/')
document.querySelectorAll('form[name="some-form"] input').forEach(i => console.log(i.name, i.value))
```

---

**Last Updated**: 2026-06-12
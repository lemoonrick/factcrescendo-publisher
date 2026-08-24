# How to push an update to every site

Once a site is running version 4.1.0 or later, you never upload a ZIP again.
You publish a release here, and every site shows the normal WordPress
"update available" prompt within about 12 hours.

## The three steps

## Picking the number

Three parts, `MAJOR.MINOR.PATCH` — and the last one does most of the work:

| Change | Bump | Example |
|---|---|---|
| Styling tweak, wording, bug fix | **PATCH** | 4.6.0 → 4.6.1 |
| A new capability or setting | **MINOR** | 4.6.1 → 4.7.0 |
| Something that breaks existing sites | **MAJOR** | 4.7.0 → 5.0.0 |

Most releases are patches. Reserve the middle number for things an editor
would actually notice as new — a new field, a new shortcode, a new setting.
Bumping it for a colour change makes the history impossible to read back.

**1. Change the version number in two places** in `factcrescendo-publisher.php`:

```
 * Version:           4.1.1
define( 'FC_PUBLISHER_VERSION', '4.1.1' );
```

Both must match, and the number must be higher than the last release.
WordPress compares these numbers to decide whether an update exists.

**2. Commit and push** your changes to the `main` branch.

**3. Tag it** &mdash; and optionally publish a release.

Tagging alone is enough. From version 4.4.0 the plugin checks both published
releases *and* plain tags, and uses whichever is newer:

```
git tag -a v4.1.1 -m "v4.1.1"
git push origin v4.1.1
```

Publishing a proper release on top is still worth doing, because it is the
only way to give editors notes in the "View details" popup:

- Go to the repository, click **Releases**, then **Draft a new release**
- In "Choose a tag", type `v4.1.1` and pick **Create new tag on publish**
- Title it `v4.1.1`
- Write a short note about what changed (this is what editors see when they
  click "View details" next to the update)
- Click **Publish release**

That's it. Nothing else to do.

## What the sites do

Each site checks GitHub roughly twice a day and remembers the answer for
12 hours. To see an update immediately instead of waiting, go to
**Plugins** on that site and click **Check for updates** under
FactCrescendo Publisher.

## Two things to know

- **The very first install on each site is still manual.** A site has to be
  running 4.1.0 or later before it knows how to update itself. So install
  4.1.0 once per site by hand, and everything after that is automatic.
- **The version number is what matters most.** If you tag or release but
  forget to raise the version inside the plugin file, sites will not see an
  update. The tag and the plugin file must agree.
- **Before 4.4.0 a tag was not enough.** Versions 4.1.1, 4.2.0 and 4.3.0 were
  tagged but never published as releases, so every site kept reporting it was
  up to date. That is fixed &mdash; tags now count.

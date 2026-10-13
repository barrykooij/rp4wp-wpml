### 3.0.0: October 13, 2026
* Feature: Works with Related Posts for WordPress 3.0 and newer, in the free plugin as well as in Premium. Premium 2.x keeps working as before.
* Feature: Posts of post types WPML does not translate stay related posts of translated posts.
* Tweak: A post that is created and published at once, such as through the REST API, an import or WP-CLI, is read and linked once WPML has stored its language, so it gets related posts in its own language.
* Tweak: With Premium 3.0.1 or newer, the posts that fill up the related posts (force fill) are in the language of the post too.
* Tweak: In 3.x the language of a post for the ignored words comes from Related Posts for WordPress itself, which reads it from WPML.

### 1.0.3: March 30, 2017
* Tweak: Fixed an issue that caused new posts not to find related posts not-main language.

### 1.0.2: January 16, 2016
* Tweak: Fixed SQL error caused by SQL structure change in core plugin.

### 1.0.1: March 27, 2015
* Tweak: Made SQL alteration work for other post types than post.

### 1.0.0: March 26, 2015
* Tweak: Initial release
<?php
/**
 * Title: Default Footer
 * Slug: therestart/footer-default
 * Categories: footer
 * Block Types: core/template-part/footer
 * Description: Footer with site title.
 */
?>
<!-- wp:group {"className":"site-footer","style":{"color":{"background":"#193540","text":"#9fd4b3"},"spacing":{"padding":{"top":"var(--wp--preset--spacing--large)","bottom":"var(--wp--preset--spacing--medium)"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group site-footer has-text-color has-background" style="background-color:#193540;color:#9fd4b3;padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--medium)">

    <!-- wp:group {"className":"site-footer__columns","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"top"},"style":{"spacing":{"blockGap":"3rem","margin":{"bottom":"var(--wp--preset--spacing--large)"}}}} -->
    <div class="wp-block-group site-footer__columns" style="margin-bottom:var(--wp--preset--spacing--large)">

        <!-- Brand column -->
        <!-- wp:group {"style":{"spacing":{"blockGap":"1rem"}}} -->
        <div class="wp-block-group">

            <!-- wp:html -->
            <a href="/" class="site-footer__brand" aria-label="the ReStart home">
                <span class="site-footer__logo" aria-hidden="true"></span>
            </a>
            <!-- /wp:html -->

            <!-- wp:paragraph {"style":{"typography":{"fontFamily":"var(--wp--preset--font-family--libre-caslon-text)","fontSize":"14px","lineHeight":"1.6"},"color":{"text":"#9fd4b3"}}} -->
            <p class="has-text-color" style="color:#9fd4b3;font-family:var(--wp--preset--font-family--libre-caslon-text);font-size:14px;line-height:1.6">Gift registries for life's<br>fresh starts.</p>
            <!-- /wp:paragraph -->

            <!-- wp:social-links {"iconColor":"primary","iconColorValue":"#47b4b0","className":"is-style-logos-only","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"left"}} -->
<ul class="wp-block-social-links has-icon-color is-style-logos-only"><!-- wp:social-link {"url":"https://linkedin.com","service":"linkedin"} /-->

<!-- wp:social-link {"url":"https://facebook.com","service":"facebook"} /-->

<!-- wp:social-link {"url":"https://instagram.com","service":"instagram"} /--></ul>
<!-- /wp:social-links -->

        </div>
        <!-- /wp:group -->

        <!-- Explore column -->
        <!-- wp:group {"style":{"spacing":{"blockGap":"1rem"}}} -->
        <div class="wp-block-group">

            <!-- wp:heading {"level":6,"style":{"typography":{"fontFamily":"var(--wp--preset--font-family--montserrat)","fontWeight":"700","fontSize":"11px","letterSpacing":"0.12em","textTransform":"uppercase"},"color":{"text":"#47b4b0"},"spacing":{"margin":{"bottom":"0"}}}} -->
            <h6 class="wp-block-heading has-text-color" style="color:#47b4b0;font-family:var(--wp--preset--font-family--montserrat);font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0">Explore</h6>
            <!-- /wp:heading -->

            <!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex","orientation":"vertical"},"style":{"typography":{"fontFamily":"var(--wp--preset--font-family--montserrat)","fontSize":"13px"},"spacing":{"blockGap":"0.5rem"}},"textColor":"mint","ariaLabel":"Explore"} -->
                <!-- wp:navigation-link {"label":"Find a Registry","url":"/registry/","kind":"custom","isTopLevelLink":true} /-->
                <!-- wp:navigation-link {"label":"Articles","url":"/category/articles/","kind":"custom","isTopLevelLink":true} /-->
                <!-- wp:navigation-link {"label":"Gift Guides","url":"/category/guides/gifts/","kind":"custom","isTopLevelLink":true} /-->
                <!-- wp:navigation-link {"label":"Our Favorites","url":"/category/guides/favorites/","kind":"custom","isTopLevelLink":true} /-->
                <!-- wp:navigation-link {"label":"About Us","url":"/about-us/","kind":"custom","isTopLevelLink":true} /-->
                <!-- wp:navigation-link {"label":"FAQ","url":"/faq/","kind":"custom","isTopLevelLink":true} /-->
            <!-- /wp:navigation -->

        </div>
        <!-- /wp:group -->

        <!-- Account column -->
        <!-- wp:group {"style":{"spacing":{"blockGap":"1rem"}}} -->
        <div class="wp-block-group">

            <!-- wp:heading {"level":6,"style":{"typography":{"fontFamily":"var(--wp--preset--font-family--montserrat)","fontWeight":"700","fontSize":"11px","letterSpacing":"0.12em","textTransform":"uppercase"},"color":{"text":"#47b4b0"},"spacing":{"margin":{"bottom":"0"}}}} -->
            <h6 class="wp-block-heading has-text-color" style="color:#47b4b0;font-family:var(--wp--preset--font-family--montserrat);font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0">Account</h6>
            <!-- /wp:heading -->

            <!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex","orientation":"vertical"},"style":{"typography":{"fontFamily":"var(--wp--preset--font-family--montserrat)","fontSize":"13px"},"spacing":{"blockGap":"0.5rem"}},"textColor":"mint","ariaLabel":"Account"} -->
                <!-- wp:navigation-link {"label":"My Account","url":"/my-account/","kind":"custom","isTopLevelLink":true} /-->
                <!-- wp:navigation-link {"label":"Start a Registry","url":"/start-a-registry/","kind":"custom","isTopLevelLink":true} /-->
                <!-- wp:navigation-link {"label":"Contact","url":"#contact","kind":"custom","isTopLevelLink":true} /-->
            <!-- /wp:navigation -->

        </div>
        <!-- /wp:group -->

    </div>
    <!-- /wp:group -->

    <!-- wp:separator {"style":{"color":{"background":"#8a9ea0"}}} -->
    <hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background" style="background-color:#8a9ea0;color:#8a9ea0"/>
    <!-- /wp:separator -->

    <!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"center","verticalAlignment":"center"},"style":{"spacing":{"blockGap":"1.5rem","margin":{"top":"var(--wp--preset--spacing--small)"}}}} -->
    <div class="wp-block-group">

        <!-- wp:paragraph {"style":{"typography":{"fontSize":"12px","fontFamily":"var(--wp--preset--font-family--montserrat)"},"color":{"text":"#8a9ea0"}}} -->
        <p class="has-text-color" style="color:#8a9ea0;font-family:var(--wp--preset--font-family--montserrat);font-size:12px">© the ReStart, all rights reserved.</p>
        <!-- /wp:paragraph -->

        <!-- wp:paragraph {"style":{"typography":{"fontSize":"12px","fontFamily":"var(--wp--preset--font-family--montserrat)"},"color":{"text":"#8a9ea0"}}} -->
        <p class="has-text-color" style="color:#8a9ea0;font-family:var(--wp--preset--font-family--montserrat);font-size:12px"><a href="/terms-and-conditions/" style="color:#8a9ea0">Terms &amp; Conditions</a></p>
        <!-- /wp:paragraph -->

        <!-- wp:paragraph {"style":{"typography":{"fontSize":"12px","fontFamily":"var(--wp--preset--font-family--montserrat)"},"color":{"text":"#8a9ea0"}}} -->
        <p class="has-text-color" style="color:#8a9ea0;font-family:var(--wp--preset--font-family--montserrat);font-size:12px"><a href="/privacy-policy/" style="color:#8a9ea0">Privacy Policy</a></p>
        <!-- /wp:paragraph -->

    </div>
    <!-- /wp:group -->

</div>
<!-- /wp:group -->

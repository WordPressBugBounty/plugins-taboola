(function () {
    var PUBLISHER_ID = '{{PUBLISHER_ID}}';
    var PAGE_TYPE = '{{PAGE_TYPE}}';
    var LOADER_URL = '//cdn.taboola.com/libtrc/' + PUBLISHER_ID + '/loader.js';
    var LOADER_PRIVACY_URL = '//static.tblcontent.com/libtrc/' + PUBLISHER_ID + '/loader.privacy.js';
    var PIXEL_URL = 'https://static.qovani.com/libtrc/tr5?type=pixel&publisher=' + PUBLISHER_ID;
    var SCRIPT_ID = 'tb_loader_script';
    var FALLBACK_ID = 'tb_loader_script_fb';
    var SHIM_GRACE = 500;
    var MAX_WAIT = 6000;

    window._taboola = window._taboola || [];

    var pageTypePush = {};
    pageTypePush[PAGE_TYPE] = 'auto';
    _taboola.push(pageTypePush);

    _taboola.push({listenTo: 'render', handler: function (p) { TRC.modDebug.logMessageToServer(2, "wordpress-integ"); }});

    _taboola.push({additional_data: {sdkd: {
        "os": "Wordpress",
        "osv": "{{WORDPRESS_VERSION}}",
        "php_ver": "{{PHP_VERSION}}",
        "sdkt": "Taboola Wordpress Plugin",
        "sdkv": "{{PLUGIN_VERSION}}",
        "loc_mid": "{{LOC_MID}}",
        "loc_home": "{{LOC_HOME}}"
    }}});

    if (document.getElementById(SCRIPT_ID)) { return; }

    new Image().src = PIXEL_URL;

    var firstScript = document.getElementsByTagName('script')[0];
    var fallbackDone = false;

    function isLoaderReady() {
        return typeof window.TRCImpl !== 'undefined' || (typeof window.TRC !== 'undefined' && window.TRC !== null);
    }

    function injectPrivacyLoader() {
        if (fallbackDone || isLoaderReady()) { return; }
        fallbackDone = true;
        var primary = document.getElementById(SCRIPT_ID);
        if (primary && primary.parentNode) { primary.parentNode.removeChild(primary); }
        if (document.getElementById(FALLBACK_ID)) { return; }
        var s = document.createElement('script');
        s.async = true;
        s.src = LOADER_PRIVACY_URL;
        s.id = FALLBACK_ID;
        firstScript.parentNode.insertBefore(s, firstScript);
    }

    var primaryScript = document.createElement('script');
    primaryScript.async = true;
    primaryScript.src = LOADER_URL;
    primaryScript.id = SCRIPT_ID;
    primaryScript.onerror = injectPrivacyLoader;
    primaryScript.onload = function () {
        setTimeout(function () {
            if (!isLoaderReady()) { injectPrivacyLoader(); }
        }, SHIM_GRACE);
    };
    firstScript.parentNode.insertBefore(primaryScript, firstScript);

    setTimeout(function () {
        if (!isLoaderReady()) { injectPrivacyLoader(); }
    }, MAX_WAIT);

    if (window.performance && typeof window.performance.mark === 'function') {
        window.performance.mark('tbl_ic');
    }
})();

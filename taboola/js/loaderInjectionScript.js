window._taboola = window._taboola || [];
_taboola.push({{{PAGE_TYPE}}:'auto'});
_taboola.push({listenTo:'render',handler:function(p){TRC.modDebug.logMessageToServer(2,"wordpress-integ");}});
_taboola.push({additional_data:{sdkd:{
            "os": "Wordpress",
            "osv": "{{WORDPRESS_VERSION}}",
            "php_ver": "{{PHP_VERSION}}",
            "sdkt": "Taboola Wordpress Plugin",
            "sdkv": "{{PLUGIN_VERSION}}",
            "loc_mid": "{{LOC_MID}}",
            "loc_home": "{{LOC_HOME}}"
        }
    }
});
! function(e, f, u) {
    e.async = 1;
    e.src = u;
    f.parentNode.insertBefore(e, f);
}(document.createElement('script'), document.getElementsByTagName('script')[0], '//cdn.taboola.com/libtrc/{{PUBLISHER_ID}}/loader.js');
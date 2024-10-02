function insertAfter(newNode, referenceNode) {
    referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling);
}
function getElementByXPath(xPath, doc){
    if(!doc) doc = document;
    if(doc.evaluate) return doc.evaluate(xPath, document, null, 9, null).singleNodeValue;
    // for IE
    while(xPath.charAt(0) == '/') xPath = xPath.substr(1);
    var prevElem = doc;
    var arr = xPath.split('/');
    for(var i=0; i<arr.length; i++){
        var step = arr[i].split(/(\w*)\[(\d*)\]/gi).filter(function(v){ return !(v==''||v.match(/\s/gi)) },this);
        var elem = step[0];
        var elemNum = step[1]?step[1]-1:0; // -1 since xpath is 1 based
        if(i<arr.length-1) prevElem = prevElem.getElementsByTagName(elem)[elemNum];
        else return prevElem.getElementsByTagName(elem)[elemNum];
    }
}


/**
 * Copyright (c) Mozilla Foundation http://www.mozilla.org/
 * This code is available under the terms of the MIT License
 */
if (!Array.prototype.filter) {
    Array.prototype.filter = function(fun /*, thisp*/) {
        var len = this.length >>> 0;
        if (typeof fun != "function") {
            throw new TypeError();
        }

        var res = [];
        var thisp = arguments[1];
        for (var i = 0; i < len; i++) {
            if (i in this) {
                var val = this[i]; // in case fun mutates this
                if (fun.call(thisp, val, i, this)) {
                    res.push(val);
                }
            }
        }

        return res;
    };
}
/* END MIT License code */

function injectWidgetByXpath(xpath){

    var n = getElementByXPath(xpath);

    // Default placement in case the xpath location is not found
    if (n==null){
        n = document.getElementById("tbdefault");
    }
    innerInject(n);
}

function injectWidgetByMarker(markerId){
    var markerNode = document.getElementById(markerId);
    innerInject(markerNode.parentNode);
}

function innerInject(node){

    var c = document.createElement("span");
    var s = document.createElement("script");

    // Notice that minifier will replce single quotes to double quotes. here it must remain with single quotes
    var noticeForDeveloper = "if JS crashes here, the first innerHTML value should be enclosed with single quotes instead of double, go to the minified version and change it";
    c.innerHTML = '{{HTML}}';
    s.innerHTML = "{{SCRIPT}}";

    insertAfter(c,node);
    insertAfter(s,c);
}





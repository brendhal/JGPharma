/**
 * Save a printable report as a JPG.
 *
 * The report pages are deliberately standalone — one inline <style> block,
 * system fonts, no images and no bundler — so the capture is done here rather
 * than pulled in from a screenshot library. The page's own stylesheet is
 * copied verbatim into an SVG <foreignObject> next to a clone of the report,
 * which leaves the browser to lay the report out exactly as it does on screen.
 * All this file does is rasterise the result and hand it over as a download.
 *
 * Anything that fails says so in the toolbar and points at Print, which is the
 * route that has always worked.
 */
(function () {
    'use strict';

    /** Retina-sharp without producing a file nobody can open. */
    var SCALE = 2;

    /** Browsers refuse to rasterise a canvas past roughly this on a side... */
    var MAX_SIDE = 16000;

    /** ...or past roughly this in total pixels, whichever bites first. */
    var MAX_AREA = 64 * 1024 * 1024;

    var QUALITY = 0.92;

    /** Every rule the page defines, so the clone reads like the original. */
    function pageStyles() {
        var sheets = document.querySelectorAll('style');
        var css = '';

        for (var i = 0; i < sheets.length; i += 1) {
            css += sheets[i].textContent + '\n';
        }

        return css;
    }

    /**
     * Text properties the report sets once on <body> and lets everything
     * inherit.
     *
     * Nothing inside the SVG is a <body>, so a `body { font-family: ... }`
     * rule matches nothing there and the report would come out in the SVG
     * default serif. Carrying the computed values onto the wrapper puts the
     * inheritance back.
     */
    var INHERITED = [
        'font-family',
        'font-size',
        'font-weight',
        'font-style',
        'line-height',
        'letter-spacing',
        'font-variant-numeric',
        'text-align',
        'color',
        '-webkit-font-smoothing'
    ];

    function wrapperFor(clone) {
        var wrapper = document.createElement('div');
        var body = window.getComputedStyle(document.body);

        for (var i = 0; i < INHERITED.length; i += 1) {
            wrapper.style.setProperty(INHERITED[i], body.getPropertyValue(INHERITED[i]));
        }

        var styles = document.createElement('style');
        styles.textContent = pageStyles();

        wrapper.appendChild(styles);
        wrapper.appendChild(clone);

        return wrapper;
    }

    /**
     * The report, wrapped in an SVG the browser can draw onto a canvas.
     *
     * The viewBox stays at natural size while width and height are multiplied
     * out, so the text is rasterised at the larger size rather than being
     * blown up afterwards and going soft.
     *
     * The markup is serialised from a real element rather than assembled as a
     * string, which leaves the browser to escape the stylesheet and the
     * attributes into XML the SVG parser will accept.
     */
    function svgFor(element, width, height, scale) {
        var clone = element.cloneNode(true);

        // The sheet is the whole picture now, so it sits at the origin without
        // the grey page furniture it normally floats on.
        clone.style.margin = '0';
        clone.style.borderRadius = '0';
        clone.style.boxShadow = 'none';
        clone.style.maxWidth = 'none';
        clone.style.width = width + 'px';

        return [
            '<svg xmlns="http://www.w3.org/2000/svg" ',
            'width="', Math.round(width * scale), '" ',
            'height="', Math.round(height * scale), '" ',
            'viewBox="0 0 ', width, ' ', height, '">',
            '<foreignObject x="0" y="0" width="100%" height="100%">',
            new XMLSerializer().serializeToString(wrapperFor(clone)),
            '</foreignObject>',
            '</svg>'
        ].join('');
    }

    /**
     * How far this sheet can be enlarged before the browser gives up on it.
     *
     * Sharpness is the first thing to go, and that is fine — an image at
     * natural size still reads. Going below natural size is not fine: a
     * thousand-row report squeezed into the canvas limit comes out a few
     * hundred pixels wide and nobody can read a figure on it. Zero means the
     * report is too long to be one image, and the caller says so rather than
     * handing over something illegible.
     */
    function scaleFor(width, height) {
        var scale = Math.min(
            SCALE,
            MAX_SIDE / width,
            MAX_SIDE / height,
            Math.sqrt(MAX_AREA / (width * height))
        );

        return scale < 1 ? 0 : scale;
    }

    function rasterise(svg, width, height, scale) {
        return new Promise(function (resolve, reject) {
            var image = new Image();

            image.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(width * scale);
                canvas.height = Math.round(height * scale);

                var context = canvas.getContext('2d');

                // A JPEG has no transparency, so anything not painted would
                // come out black — the rounded corners especially.
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);

                canvas.toBlob(function (blob) {
                    if (blob) {
                        resolve(blob);
                    } else {
                        reject(new Error('this browser could not encode a JPG'));
                    }
                }, 'image/jpeg', QUALITY);
            };

            image.onerror = function () {
                reject(new Error('the report could not be drawn'));
            };

            image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
        });
    }

    function save(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');

        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();

        window.setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 60000);
    }

    /** Says what went wrong in the toolbar rather than in a browser dialog. */
    function note(button, message, isError) {
        var slot = button.parentNode.querySelector('[data-download-image-note]');

        if (! slot) {
            slot = document.createElement('span');
            slot.setAttribute('data-download-image-note', '');
            slot.style.alignSelf = 'center';
            slot.style.fontSize = '12px';
            button.parentNode.insertBefore(slot, button);
        }

        slot.style.color = isError ? '#b91c1c' : '#6b7280';
        slot.textContent = message;
    }

    function captureSheet(sheet, filename) {
        var width = sheet.offsetWidth;
        var height = sheet.scrollHeight;

        if (! width || ! height) {
            return Promise.reject(new Error('the report is empty'));
        }

        var scale = scaleFor(width, height);

        if (! scale) {
            return Promise.reject(new Error('this report is too long to fit in one image'));
        }

        return rasterise(svgFor(sheet, width, height, scale), width, height, scale)
            .then(function (blob) {
                save(blob, filename);
            });
    }

    function capture(button) {
        var selector = button.getAttribute('data-target') || '.page';
        var sheets = document.querySelectorAll(selector);
        var base = button.getAttribute('data-filename') || 'report';

        if (! sheets.length) {
            note(button, 'Nothing to save.', true);

            return;
        }

        var label = button.textContent;
        button.disabled = true;
        button.textContent = 'Preparing…';
        note(button, '', false);

        var work = Promise.resolve();

        Array.prototype.forEach.call(sheets, function (sheet, index) {
            work = work.then(function () {
                // One sheet, one file. A supplier claims run prints a sheet per
                // supplier, and each of those goes to a different supplier.
                var name = sheets.length > 1
                    ? base + '-' + (index + 1) + '.jpg'
                    : base + '.jpg';

                return captureSheet(sheet, name).then(function () {
                    // Chrome asks before letting a page save several files in a
                    // row; spacing them out keeps that prompt to one.
                    return new Promise(function (resolve) {
                        window.setTimeout(resolve, 350);
                    });
                });
            });
        });

        work.then(function () {
            note(button, sheets.length > 1 ? sheets.length + ' images saved.' : '', false);
        }).catch(function (error) {
            note(button, 'Could not save an image — ' + error.message + '. Use Print instead.', true);
        }).then(function () {
            button.disabled = false;
            button.textContent = label;
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;

        if (! target || typeof target.closest !== 'function') {
            return;
        }

        var button = target.closest('[data-download-image]');

        if (button) {
            event.preventDefault();
            capture(button);
        }
    });
}());

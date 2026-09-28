(function (window) {
    'use strict';

    if (!window.Phaser) {
        console.error('[VizuraRuntime] Phaser is not loaded.');
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('game-container');
            if (el) {
                el.textContent = 'موتور Phaser بارگذاری نشد.';
            }
        });
        return;
    }

    function parseColor(value, fallback) {
        if (value == null || value === '') {
            return fallback;
        }

        if (typeof value === 'number' && isFinite(value)) {
            return value & 0xffffff;
        }

        var text = String(value).trim();

        var shortHex = /^#([0-9a-f]{3})$/i.exec(text);
        if (shortHex) {
            var h = shortHex[1];
            return parseInt(h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2), 16);
        }

        var longHex = /^#([0-9a-f]{6})$/i.exec(text);
        if (longHex) {
            return parseInt(longHex[1], 16);
        }

        var hex = /^0x([0-9a-f]{6})$/i.exec(text);
        if (hex) {
            return parseInt(hex[1], 16);
        }

        return fallback;
    }

    function toCss(color) {
        var hex = (color & 0xffffff).toString(16);
        while (hex.length < 6) {
            hex = '0' + hex;
        }
        return '#' + hex;
    }

    function shiftColor(color, amount) {
        var clamp = function (n) {
            return Math.max(0, Math.min(255, n));
        };
        var r = clamp(((color >> 16) & 255) + amount);
        var g = clamp(((color >> 8) & 255) + amount);
        var b = clamp((color & 255) + amount);
        return (r << 16) | (g << 8) | b;
    }

    function luminance(color) {
        var r = (color >> 16) & 255;
        var g = (color >> 8) & 255;
        var b = color & 255;
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    }

    function resolveBackground(definition) {
        definition = definition || {};
        var background = definition.background || {};
        var explicitTop = background.top != null ? background.top : definition.backgroundTop;
        var explicitBottom = background.bottom != null ? background.bottom : definition.backgroundBottom;
        var single = background.color != null ? background.color : definition.backgroundColor;

        var top;
        var bottom;

        if (explicitTop != null || explicitBottom != null) {
            bottom = parseColor(explicitBottom != null ? explicitBottom : explicitTop, 0xf472b6);
            top = parseColor(explicitTop != null ? explicitTop : explicitBottom, 0x312e81);
        } else if (single != null) {
            var base = parseColor(single, 0x7c3aed);
            top = shiftColor(base, 28);
            bottom = shiftColor(base, -24);
        } else {
            top = 0x4f46e5;
            bottom = 0xf472b6;
        }

        var decorations = background.decorations !== false && definition.decorations !== false;

        return {
            top: top,
            bottom: bottom,
            decorations: decorations
        };
    }

    function ensureLayout() {
        var html = document.documentElement;
        var body = document.body;
        if (!html || !body) {
            return;
        }

        html.style.margin = '0';
        html.style.height = '100%';
        html.style.background = '#0b1020';
        body.style.margin = '0';
        body.style.height = '100%';
        body.style.overflow = 'hidden';
        body.style.background = '#0b1020';

        var parent = document.getElementById('game-container');
        if (parent) {
            parent.style.width = '100%';
            parent.style.height = '100%';
            parent.style.minHeight = '100vh';
            parent.style.overflow = 'hidden';
        }
    }

    var VizuraGame = {
        scene: null,
        config: null,
        definition: null,
        game: null,
        score: 0,
        finished: false,
        objects: [],
        buttons: [],
        chrome: [],
        state: null,
        sounds: null,
        _api: null,
        updateFailed: false,

        define(definition) {
            if (!definition || typeof definition !== 'object') {
                throw new Error('[VizuraGame] Invalid game definition.');
            }

            this.definition = definition;
            this.config = {
                width: Number(definition.width) || 960,
                height: Number(definition.height) || 600
            };

            console.log('[VizuraGame] Definition registered.');

            return this;
        },

        init(scene) {
            this.scene = scene;
            this.finished = false;
            this.score = 0;
            this.objects = [];
            this.buttons = [];
            this.chrome = [];
            this.state = {};
            this._api = null;
            this.updateFailed = false;

            if (!this.definition) {
                throw new Error(
                    '[VizuraGame] No game definition found. Call VizuraGame.define(...) first.'
                );
            }

            this.setupAudio();
            this.paintBackground();

            if (typeof this.definition.start === 'function') {
                var api = this.api();
                try {
                    // `this` inside start() is the API. The API is also
                    // passed as the first argument for start(api) style.
                    this.definition.start.call(api, api);
                } catch (error) {
                    console.error('[VizuraRuntime] start() failed:', error);
                    this.showError(error);
                }
            }
        },

        update(time, delta) {
            if (!this.definition || this.updateFailed) {
                return;
            }

            if (typeof this.definition.update === 'function') {
                try {
                    this.definition.update.call(this.api(), time, delta);
                } catch (error) {
                    this.updateFailed = true;
                    console.error('[VizuraRuntime] update() failed:', error);
                    this.showError(error);
                }
            }
        },

        api() {
            if (this._api) {
                return this._api;
            }

            var self = this;

            this._api = {
                scene: self.scene,

                get score() {
                    return self.score;
                },

                set score(value) {
                    self.score = Number(value) || 0;
                },

                get state() {
                    return self.state;
                },

                set state(value) {
                    self.state = value && typeof value === 'object' ? value : {};
                },

                get width() {
                    return self.config.width;
                },

                get height() {
                    return self.config.height;
                },

                text(options) {
                    return self.createText(options);
                },

                button(options) {
                    return self.createButton(options);
                },

                rect(options) {
                    return self.createRect(options);
                },

                circle(options) {
                    return self.createCircle(options);
                },

                emoji(options) {
                    return self.createEmoji(options);
                },

                line(options) {
                    return self.createLine(options);
                },

                panel(options) {
                    return self.createPanel(options);
                },

                star(options) {
                    return self.createStar(options);
                },

                badge(options) {
                    return self.createBadge(options);
                },

                background(options) {
                    return self.setBackground(options);
                },

                animate(target, options) {
                    return self.animate(target, options);
                },

                correct(options) {
                    return self.correct(options);
                },

                wrong(options) {
                    return self.wrong(options);
                },

                finish(options) {
                    return self.finish(options);
                },

                playSound(type) {
                    return self.playSound(type);
                },

                clear() {
                    return self.clear();
                },

                delay(ms, callback) {
                    if (!self.scene) {
                        return null;
                    }

                    return self.scene.time.delayedCall(ms, function () {
                        if (typeof callback !== 'function') {
                            return;
                        }
                        try {
                            callback.call(self.api());
                        } catch (error) {
                            console.error('[VizuraRuntime] delay() callback failed:', error);
                            self.showError(error);
                        }
                    });
                },

                random(min, max) {
                    return Phaser.Math.Between(min, max);
                },

                randomItem(array) {
                    if (!Array.isArray(array) || !array.length) {
                        return null;
                    }

                    return array[
                        Phaser.Math.Between(0, array.length - 1)
                    ];
                }
            };

            return this._api;
        },

        callHandler(fn, arg) {
            if (typeof fn !== 'function') {
                return;
            }

            try {
                fn.call(this.api(), arg);
            } catch (error) {
                console.error('[VizuraRuntime] callback failed:', error);
                this.showError(error);
            }
        },

        showError(error) {
            if (!this.scene) {
                return;
            }

            var message = String(error && error.message ? error.message : error);
            var width = this.config.width;
            var height = this.config.height;

            var box = this.scene.add.rectangle(
                width / 2,
                height / 2,
                Math.min(width - 80, 720),
                180,
                0x7f1d1d,
                0.96
            );
            box.setDepth(3000);

            var text = this.scene.add.text(
                width / 2,
                height / 2,
                'خطای بازی:\n' + message,
                {
                    fontFamily: 'Vazir, Tahoma, Arial, sans-serif',
                    fontSize: '22px',
                    color: '#ffffff',
                    align: 'center',
                    rtl: true,
                    wordWrap: { width: Math.min(width - 140, 640) }
                }
            );
            text.setOrigin(0.5);
            text.setDepth(3001);
        },

        paintBackground() {
            var scene = this.scene;
            if (!scene) {
                return;
            }

            this.chrome.forEach(function (object) {
                if (object && object.destroy) {
                    object.destroy();
                }
            });
            this.chrome = [];

            var colors = resolveBackground(this.definition);
            var width = this.config.width;
            var height = this.config.height;

            var sky = scene.add.graphics();
            sky.fillGradientStyle(colors.top, colors.top, colors.bottom, colors.bottom, 1, 1, 1, 1);
            sky.fillRect(0, 0, width, height);
            sky.setDepth(-100);
            this.chrome.push(sky);

            if (!colors.decorations) {
                return;
            }

            var light = luminance(colors.top) > 0.72 && luminance(colors.bottom) > 0.72;
            var blobColor = light ? 0x7c3aed : 0xffffff;
            var starColor = light ? 0xf59e0b : 0xfde68a;
            var blobs = [
                { x: width * 0.1, y: height * 0.16, r: 78, a: light ? 0.12 : 0.1 },
                { x: width * 0.9, y: height * 0.14, r: 110, a: light ? 0.1 : 0.12 },
                { x: width * 0.84, y: height * 0.86, r: 130, a: light ? 0.1 : 0.1 },
                { x: width * 0.14, y: height * 0.82, r: 90, a: light ? 0.12 : 0.09 }
            ];

            blobs.forEach(function (blob) {
                var g = scene.add.graphics();
                g.fillStyle(blobColor, blob.a);
                g.fillCircle(blob.x, blob.y, blob.r);
                g.setDepth(-90);
                this.chrome.push(g);
            }, this);

            var stars = [
                { x: width * 0.18, y: height * 0.1, r: 14 },
                { x: width * 0.78, y: height * 0.12, r: 10 },
                { x: width * 0.92, y: height * 0.28, r: 8 }
            ];

            stars.forEach(function (star) {
                var g = this.drawStar(scene, star.x, star.y, star.r, star.r * 0.45, starColor, 0.85);
                g.setDepth(-80);
                this.chrome.push(g);
            }, this);
        },

        setBackground(options) {
            options = options || {};
            if (!this.definition.background || typeof this.definition.background !== 'object') {
                this.definition.background = {};
            }

            if (options.top != null) {
                this.definition.background.top = options.top;
            }
            if (options.bottom != null) {
                this.definition.background.bottom = options.bottom;
            }
            if (options.color != null) {
                this.definition.background.color = options.color;
            }
            if (options.decorations != null) {
                this.definition.background.decorations = options.decorations;
            }

            this.paintBackground();
            return this.definition.background;
        },

        drawStar(scene, x, y, outer, inner, color, alpha) {
            var graphics = scene.add.graphics();
            var points = 5;
            graphics.fillStyle(color, alpha == null ? 1 : alpha);
            graphics.beginPath();

            for (var i = 0; i < points * 2; i++) {
                var radius = i % 2 === 0 ? outer : inner;
                var angle = -Math.PI / 2 + (i * Math.PI) / points;
                var px = Math.cos(angle) * radius;
                var py = Math.sin(angle) * radius;
                if (i === 0) {
                    graphics.moveTo(px, py);
                } else {
                    graphics.lineTo(px, py);
                }
            }

            graphics.closePath();
            graphics.fillPath();
            graphics.setPosition(x, y);
            return graphics;
        },

        createText(options) {
            options = options || {};
            var scene = this.scene;
            var x = Number(options.x != null ? options.x : this.config.width / 2);
            var y = Number(options.y != null ? options.y : this.config.height / 2);
            var color = toCss(parseColor(options.color, 0xffffff));

            var text = scene.add.text(
                x,
                y,
                String(options.text != null ? options.text : ''),
                {
                    fontFamily: options.fontFamily || 'Vazir, Tahoma, Arial, sans-serif',
                    fontSize: options.size ? String(Number(options.size)) + 'px' : '32px',
                    color: color,
                    fontStyle: options.bold ? 'bold' : 'normal',
                    align: options.align || 'center',
                    rtl: true,
                    wordWrap: options.wordWrapWidth
                        ? { width: Number(options.wordWrapWidth) }
                        : undefined
                }
            );

            text.setOrigin(
                Number(options.originX != null ? options.originX : 0.5),
                Number(options.originY != null ? options.originY : 0.5)
            );

            if (options.backgroundColor) {
                text.setBackgroundColor(toCss(parseColor(options.backgroundColor, 0x111827)));
            }

            if (options.padding) {
                text.setPadding(options.padding);
            }

            if (options.stroke || options.strokeColor) {
                text.setStroke(
                    toCss(parseColor(options.strokeColor != null ? options.strokeColor : options.stroke, 0x111827)),
                    Number(options.strokeThickness || options.strokeWidth || 4)
                );
            }

            if (options.shadow) {
                text.setShadow(0, 3, 'rgba(15, 23, 42, 0.35)', 6, true, true);
            }

            if (options.depth !== undefined) {
                text.setDepth(options.depth);
            }

            this.objects.push(text);
            return text;
        },

        createButton(options) {
            options = options || {};
            var scene = this.scene;
            var self = this;
            var x = Number(options.x != null ? options.x : this.config.width / 2);
            var y = Number(options.y != null ? options.y : this.config.height / 2);
            var width = Number(options.width || 200);
            var height = Number(options.height || 76);
            var radius = Number(options.radius != null ? options.radius : 22);
            var transparent = options.transparent === true || Number(options.alpha) === 0;
            var normalColor = parseColor(options.color != null ? options.color : options.bgColor, 0x4f46e5);
            var hoverColor = parseColor(options.hoverColor, shiftColor(normalColor, 24));
            var pressedColor = parseColor(options.pressedColor, shiftColor(normalColor, -28));

            var container = scene.add.container(x, y);
            var shadow = scene.add.graphics();
            var face = scene.add.graphics();
            var label = scene.add.text(0, 0, String(options.text != null ? options.text : ''), {
                fontFamily: 'Vazir, Tahoma, Arial, sans-serif',
                fontSize: String(Number(options.fontSize || 28)) + 'px',
                color: toCss(parseColor(options.textColor, 0xffffff)),
                fontStyle: options.bold === false ? 'normal' : 'bold',
                align: 'center',
                rtl: true
            });
            label.setOrigin(0.5);

            var draw = function (color) {
                face.clear();
                if (transparent) {
                    return;
                }

                var top = shiftColor(color, 42);
                var bottom = shiftColor(color, -30);
                face.fillGradientStyle(top, top, bottom, bottom, 1, 1, 1, 1);
                face.fillRoundedRect(-width / 2, -height / 2, width, height, radius);
                face.fillStyle(0xffffff, 0.24);
                face.fillRoundedRect(
                    -width / 2 + 10,
                    -height / 2 + 7,
                    width - 20,
                    Math.max(16, height * 0.38),
                    Math.max(8, radius - 8)
                );
                face.lineStyle(2, 0xffffff, 0.4);
                face.strokeRoundedRect(-width / 2 + 1, -height / 2 + 1, width - 2, height - 2, radius);
            };

            if (!transparent) {
                shadow.fillStyle(0x0f172a, 0.28);
                shadow.fillRoundedRect(-width / 2 + 2, -height / 2 + 8, width, height, radius);
            }

            draw(normalColor);
            container.add([shadow, face, label]);

            if (options.depth !== undefined) {
                container.setDepth(options.depth);
            }

            var zone = scene.add.zone(x, y, width, height);
            zone.setOrigin(0.5, 0.5);
            zone.setInteractive({ useHandCursor: true });
            zone.setDepth((options.depth || 0) + 5);

            var button = {
                background: face,
                label: label,
                gameObject: container,
                zone: zone,

                setText(value) {
                    label.setText(String(value));
                },

                setVisible(value) {
                    container.setVisible(value);
                    zone.setVisible(value);
                    if (value) {
                        zone.setInteractive({ useHandCursor: true });
                    } else {
                        zone.disableInteractive();
                    }
                },

                destroy() {
                    container.destroy();
                    zone.destroy();
                }
            };

            zone.on('pointerover', function () {
                draw(hoverColor);
                if (!transparent) {
                    scene.tweens.killTweensOf(container);
                    scene.tweens.add({
                        targets: container,
                        scaleX: 1.04,
                        scaleY: 1.04,
                        duration: 90
                    });
                }
                self.callHandler(options.onHover, button);
            });

            zone.on('pointerout', function () {
                draw(normalColor);
                if (!transparent) {
                    scene.tweens.killTweensOf(container);
                    scene.tweens.add({
                        targets: container,
                        scaleX: 1,
                        scaleY: 1,
                        duration: 90
                    });
                }
                self.callHandler(options.onOut, button);
            });

            zone.on('pointerdown', function () {
                draw(pressedColor);
                self.resumeAudio();
                self.playSound('click');
                self.callHandler(options.onDown, button);
            });

            zone.on('pointerup', function () {
                draw(hoverColor);
                self.callHandler(options.onClick, button);
            });

            this.buttons.push(button);
            return button;
        },

        createRect(options) {
            options = options || {};
            var x = Number(options.x != null ? options.x : this.config.width / 2);
            var y = Number(options.y != null ? options.y : this.config.height / 2);
            var width = Number(options.width || 100);
            var height = Number(options.height || 100);
            var radius = Number(options.radius || 0);
            var color = parseColor(options.color, 0x4f46e5);
            var alpha = options.alpha != null ? options.alpha : 1;
            var graphics = this.scene.add.graphics();

            var fillMain = function () {
                if (options.gradient) {
                    var top = parseColor(options.colorTop, shiftColor(color, 36));
                    var bottom = parseColor(options.colorBottom, shiftColor(color, -28));
                    graphics.fillGradientStyle(top, top, bottom, bottom, alpha, alpha, alpha, alpha);
                } else {
                    graphics.fillStyle(color, alpha);
                }

                if (radius) {
                    graphics.fillRoundedRect(-width / 2, -height / 2, width, height, radius);
                } else {
                    graphics.fillRect(-width / 2, -height / 2, width, height);
                }
            };

            if (options.shadow) {
                graphics.fillStyle(0x0f172a, 0.2);
                if (radius) {
                    graphics.fillRoundedRect(-width / 2 + 3, -height / 2 + 8, width, height, radius);
                } else {
                    graphics.fillRect(-width / 2 + 3, -height / 2 + 8, width, height);
                }
            }

            fillMain();

            var strokeColor = options.strokeColor != null ? options.strokeColor : options.stroke;
            if (strokeColor !== undefined) {
                graphics.lineStyle(
                    Number(options.strokeWidth || 2),
                    parseColor(strokeColor, 0xffffff),
                    options.strokeAlpha != null ? options.strokeAlpha : 1
                );

                if (radius) {
                    graphics.strokeRoundedRect(-width / 2, -height / 2, width, height, radius);
                } else {
                    graphics.strokeRect(-width / 2, -height / 2, width, height);
                }
            }

            graphics.setPosition(x, y);

            if (options.depth !== undefined) {
                graphics.setDepth(options.depth);
            }

            this.objects.push(graphics);
            return graphics;
        },

        createCircle(options) {
            options = options || {};
            var x = Number(options.x != null ? options.x : this.config.width / 2);
            var y = Number(options.y != null ? options.y : this.config.height / 2);
            var radius = Number(options.radius || 40);
            var color = parseColor(options.color, 0x4f46e5);
            var alpha = options.alpha != null ? options.alpha : 1;
            var graphics = this.scene.add.graphics();

            if (options.shadow) {
                graphics.fillStyle(0x0f172a, 0.2);
                graphics.fillCircle(3, 8, radius);
            }

            graphics.fillStyle(color, alpha);
            graphics.fillCircle(0, 0, radius);

            if (options.gloss) {
                graphics.fillStyle(0xffffff, 0.28);
                graphics.fillCircle(-radius * 0.22, -radius * 0.28, radius * 0.38);
            }

            var strokeColor = options.strokeColor != null ? options.strokeColor : options.stroke;
            if (strokeColor !== undefined) {
                graphics.lineStyle(
                    Number(options.strokeWidth || 4),
                    parseColor(strokeColor, 0xffffff),
                    1
                );
                graphics.strokeCircle(0, 0, radius);
            }

            graphics.setPosition(x, y);

            if (options.depth !== undefined) {
                graphics.setDepth(options.depth);
            }

            this.objects.push(graphics);
            return graphics;
        },

        createEmoji(options) {
            options = options || {};
            if (options.bubble) {
                this.createCircle({
                    x: options.x,
                    y: options.y,
                    radius: Number(options.bubbleRadius || (options.size || 64) * 0.72),
                    color: options.bubbleColor != null ? options.bubbleColor : 0xffffff,
                    gloss: true,
                    shadow: true,
                    depth: options.depth
                });
            }

            return this.createText({
                x: options.x,
                y: options.y,
                text: options.text || '⭐',
                size: options.size || 64,
                depth: options.depth != null ? options.depth + 1 : undefined,
                originX: options.originX,
                originY: options.originY
            });
        },

        createLine(options) {
            options = options || {};
            var graphics = this.scene.add.graphics();

            graphics.lineStyle(
                Number(options.width || 4),
                parseColor(options.color, 0xffffff),
                options.alpha != null ? options.alpha : 1
            );

            graphics.beginPath();
            graphics.moveTo(Number(options.x1 || 0), Number(options.y1 || 0));
            graphics.lineTo(Number(options.x2 || 0), Number(options.y2 || 0));
            graphics.strokePath();

            if (options.depth !== undefined) {
                graphics.setDepth(options.depth);
            }

            this.objects.push(graphics);
            return graphics;
        },

        createPanel(options) {
            options = options || {};
            return this.createRect({
                x: options.x,
                y: options.y,
                width: options.width || 420,
                height: options.height || 140,
                radius: options.radius != null ? options.radius : 28,
                color: options.color != null ? options.color : 0xffffff,
                alpha: options.alpha,
                gradient: options.gradient !== false,
                shadow: options.shadow !== false,
                strokeColor: options.strokeColor != null ? options.strokeColor : 0xffffff,
                strokeAlpha: options.strokeAlpha != null ? options.strokeAlpha : 0.45,
                strokeWidth: options.strokeWidth != null ? options.strokeWidth : 3,
                depth: options.depth
            });
        },

        createStar(options) {
            options = options || {};
            var outer = Number(options.radius || options.size || 24);
            var graphics = this.drawStar(
                this.scene,
                Number(options.x != null ? options.x : this.config.width / 2),
                Number(options.y != null ? options.y : this.config.height / 2),
                outer,
                Number(options.innerRadius || outer * 0.45),
                parseColor(options.color, 0xfbbf24),
                options.alpha != null ? options.alpha : 1
            );

            if (options.depth !== undefined) {
                graphics.setDepth(options.depth);
            }

            this.objects.push(graphics);
            return graphics;
        },

        createBadge(options) {
            options = options || {};
            var textValue = String(options.text != null ? options.text : '');
            var width = Number(options.width || Math.max(150, textValue.length * 22 + 56));
            var height = Number(options.height || 52);

            this.createRect({
                x: options.x,
                y: options.y,
                width: width,
                height: height,
                radius: height / 2,
                color: options.color != null ? options.color : 0xfbbf24,
                gradient: true,
                shadow: true,
                depth: options.depth
            });

            return this.createText({
                x: options.x,
                y: options.y,
                text: textValue,
                size: options.size || 22,
                color: options.textColor || '#1f2937',
                bold: true,
                depth: (options.depth || 0) + 1
            });
        },

        animate(target, options) {
            options = options || {};
            if (!target || !this.scene) {
                return null;
            }

            var tweenTarget = target.gameObject ? target.gameObject : target;
            var config = {
                targets: tweenTarget,
                duration: Number(options.duration || 500),
                ease: options.ease || 'Sine.easeInOut',
                repeat: Number(options.repeat != null ? options.repeat : 0),
                yoyo: Boolean(options.yoyo)
            };

            ['x', 'y', 'alpha', 'scale', 'scaleX', 'scaleY', 'angle', 'rotation'].forEach(function (property) {
                if (options[property] !== undefined) {
                    config[property] = options[property];
                }
            });

            if (typeof options.onComplete === 'function') {
                var self = this;
                config.onComplete = function () {
                    self.callHandler(options.onComplete);
                };
            }

            return this.scene.tweens.add(config);
        },

        correct(options) {
            options = options || {};
            if (this.finished) {
                return;
            }

            this.score += Number(options.points != null ? options.points : 1);
            this.playSound('correct');
            this.flash(0x22c55e);
            this.callHandler(options.onCorrect, this.score);

            if (options.finish === true) {
                this.finish({
                    success: true,
                    message: options.message
                });
                return;
            }

            if (this.definition && typeof this.definition.onCorrect === 'function') {
                this.callHandler(this.definition.onCorrect, this.score);
            }
        },

        wrong(options) {
            options = options || {};
            if (this.finished) {
                return;
            }

            this.playSound('wrong');
            this.flash(0xef4444);

            if (this.scene && this.scene.cameras && this.scene.cameras.main) {
                this.scene.cameras.main.shake(
                    Number(options.shakeDuration || 180),
                    Number(options.shakeIntensity || 0.006)
                );
            }

            this.callHandler(options.onWrong, this.score);

            if (this.definition && typeof this.definition.onWrong === 'function') {
                this.callHandler(this.definition.onWrong, this.score);
            }
        },

        finish(options) {
            options = options || {};
            if (this.finished) {
                return;
            }

            this.finished = true;

            if (options.success !== false) {
                this.playSound('win');
                this.flash(0x22c55e);
            }

            if (this.definition && typeof this.definition.onFinish === 'function') {
                this.callHandler(this.definition.onFinish, {
                    score: this.score,
                    success: options.success !== false
                });
            }

            this.showFinishOverlay(options);
        },

        showFinishOverlay(options) {
            options = options || {};
            var scene = this.scene;
            var width = this.config.width;
            var height = this.config.height;
            var message = options.message || (options.success === false ? 'دوباره تلاش کن!' : 'آفرین! بازی تمام شد');
            var boxW = Math.min(width - 80, 680);
            var boxH = 300;
            var boxX = (width - boxW) / 2;
            var boxY = (height - boxH) / 2;

            var box = scene.add.graphics();
            box.fillStyle(0x111827, 0.94);
            box.fillRoundedRect(boxX, boxY, boxW, boxH, 32);
            box.setDepth(1000);

            var title = scene.add.text(width / 2, boxY + 78, message, {
                fontFamily: 'Vazir, Tahoma, Arial, sans-serif',
                fontSize: '36px',
                color: '#ffffff',
                fontStyle: 'bold',
                align: 'center',
                rtl: true,
                wordWrap: { width: boxW - 60 }
            });
            title.setOrigin(0.5);
            title.setDepth(1001);

            var scoreText = scene.add.text(width / 2, boxY + 150, 'امتیاز: ' + this.score, {
                fontFamily: 'Vazir, Tahoma, Arial, sans-serif',
                fontSize: '28px',
                color: '#fde68a',
                align: 'center',
                rtl: true
            });
            scoreText.setOrigin(0.5);
            scoreText.setDepth(1001);

            var restart = this.createButton({
                x: width / 2,
                y: boxY + 230,
                width: 240,
                height: 64,
                text: 'بازی دوباره',
                fontSize: 24,
                color: 0x4f46e5,
                depth: 1002,
                onClick: function () {
                    window.location.reload();
                }
            });

            restart.gameObject.setDepth(1002);
            restart.zone.setDepth(1004);
        },

        flash(color) {
            if (!this.scene) {
                return;
            }

            var flash = this.scene.add.rectangle(
                this.config.width / 2,
                this.config.height / 2,
                this.config.width,
                this.config.height,
                color,
                0.16
            );
            flash.setDepth(900);

            this.scene.tweens.add({
                targets: flash,
                alpha: 0,
                duration: 260,
                onComplete: function () {
                    flash.destroy();
                }
            });
        },

        clear() {
            this.objects.forEach(function (object) {
                if (object && object.destroy) {
                    object.destroy();
                }
            });
            this.objects = [];

            this.buttons.forEach(function (button) {
                if (button && button.destroy) {
                    button.destroy();
                }
            });
            this.buttons = [];
        },

        setupAudio() {
            if (this.sounds) {
                return;
            }

            var AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                this.sounds = { ctx: null };
                return;
            }

            var ctx;
            try {
                ctx = new AudioContextClass();
            } catch (e) {
                ctx = null;
            }

            this.sounds = { ctx: ctx };
        },

        resumeAudio() {
            if (this.sounds && this.sounds.ctx && this.sounds.ctx.state === 'suspended') {
                this.sounds.ctx.resume().catch(function () {});
            }
        },

        playSound(type) {
            this.setupAudio();

            if (!this.sounds || !this.sounds.ctx) {
                return;
            }

            this.resumeAudio();

            var ctx = this.sounds.ctx;
            var frequencies;

            switch (type) {
                case 'correct':
                    frequencies = [523, 659, 784];
                    break;
                case 'wrong':
                    frequencies = [220, 180];
                    break;
                case 'win':
                    frequencies = [523, 659, 784, 1046];
                    break;
                case 'click':
                default:
                    frequencies = [420];
                    break;
            }

            frequencies.forEach(function (frequency, index) {
                var oscillator = ctx.createOscillator();
                var gain = ctx.createGain();

                oscillator.type = type === 'wrong' ? 'triangle' : 'sine';
                oscillator.frequency.value = frequency;
                oscillator.connect(gain);
                gain.connect(ctx.destination);

                var start = ctx.currentTime + index * 0.08;
                var duration = type === 'win' ? 0.16 : 0.1;

                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.08, start + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);

                oscillator.start(start);
                oscillator.stop(start + duration + 0.02);
            });
        }
    };

    class VizuraScene extends Phaser.Scene {
        constructor() {
            super({ key: 'VizuraScene' });
        }

        create() {
            VizuraGame.init(this);
        }

        update(time, delta) {
            VizuraGame.update(time, delta);
        }
    }

    window.VizuraGame = VizuraGame;

    window.VizuraRuntimeStart = function () {
        if (!VizuraGame.definition) {
            throw new Error('[VizuraRuntime] VizuraGame.define(...) was not called.');
        }

        if (VizuraGame.game) {
            return VizuraGame.game;
        }

        ensureLayout();

        var width = VizuraGame.config.width;
        var height = VizuraGame.config.height;
        var colors = resolveBackground(VizuraGame.definition);

        VizuraGame.game = new Phaser.Game({
            type: Phaser.AUTO,
            width: width,
            height: height,
            parent: 'game-container',
            backgroundColor: toCss(colors.bottom),
            render: {
                antialias: true,
                pixelArt: false,
                roundPixels: false
            },
            scale: {
                mode: Phaser.Scale.FIT,
                autoCenter: Phaser.Scale.CENTER_BOTH,
                width: width,
                height: height
            },
            scene: VizuraScene
        });

        return VizuraGame.game;
    };
})(window);

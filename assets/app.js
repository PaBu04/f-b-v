/* f-b-v – Upload-Komfort, Likes, Lightbox und Benachrichtigungen */
(function () {
  'use strict';

  /* ---------------------------------------- Service Worker --------- */

  /*
   * Der Service Worker nimmt Push-Nachrichten entgegen, auch wenn keine Seite
   * offen ist. Registriert wird er auf jeder Seite – ohne ihn gäbe es weder
   * Benachrichtigungen noch die Möglichkeit, sie zu abonnieren.
   */
  var swBereit = null;
  if ('serviceWorker' in navigator) {
    swBereit = navigator.serviceWorker.register('sw.js').catch(function () {
      return null;
    });
  }

  /* ---------------------------------------- Upload ----------------- */

  var input = document.getElementById('files');
  var form = document.getElementById('upload-form');
  var panel = document.getElementById('upload-panel');
  var summary = document.getElementById('upload-summary');
  var submit = document.getElementById('upload-submit');
  var cancel = document.getElementById('upload-cancel');
  var overlay = document.getElementById('drop-overlay');

  if (input && form && panel && summary) {
    // Ohne JavaScript bleibt das Feld sichtbar; hier erscheint es erst,
    // wenn wirklich Bilder ausgewählt wurden.
    panel.hidden = true;
    if (cancel) {
      cancel.hidden = false;
    }

    var note = document.getElementById('upload-note');

    var megabyte = function (bytes) {
      return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
    };

    var setNote = function (text, art) {
      if (!note) {
        return;
      }
      note.textContent = text || '';
      note.className = 'upload-note' + (art ? ' is-' + art : '');
      note.hidden = !text;
    };

    var describeSelection = function () {
      var count = input.files ? input.files.length : 0;
      if (count === 0) {
        panel.hidden = true;
        setNote('');
        return;
      }

      var bytes = 0;
      for (var i = 0; i < count; i++) {
        bytes += input.files[i].size;
      }

      summary.textContent = (count === 1 ? '1 Bild' : count + ' Bilder') + ' · ' + megabyte(bytes);
      panel.hidden = false;
    };

    /* ------------------------------------ Verkleinern im Gerät ---- */

    /*
     * Der Hoster begrenzt Uploads auf 2 MB, Handyfotos sind größer. Deshalb
     * rechnet der Browser sie herunter – und zwar direkt nach der Auswahl.
     *
     * Das hat einen zweiten Grund: Ein <input type="file"> hält nur einen
     * Verweis auf die Datei. Beim Absenden liest der Browser sie erneut von
     * der Platte und bricht mit "Your file couldn't be accessed" ab, wenn sie
     * inzwischen verschoben oder verändert wurde – auf Handys passiert das
     * regelmäßig, weil Verweise aus der Fotogalerie nur kurz gelten. Nach der
     * Aufbereitung liegen die Bilder im Speicher, dieser Fehler ist damit
     * ausgeschlossen.
     */
    var maxEdge = parseInt(form.getAttribute('data-max-edge'), 10) || 0;
    var quality = (parseInt(form.getAttribute('data-quality'), 10) || 85) / 100;
    var limit = parseInt(form.getAttribute('data-limit'), 10) || 0;

    var canScale = maxEdge > 0
      && !!window.File
      && !!window.URL
      && !!window.HTMLCanvasElement
      && !!HTMLCanvasElement.prototype.toBlob;

    var canSend = !!(window.FormData && window.XMLHttpRequest);

    // Bild laden – nach Möglichkeit mit Beachtung der EXIF-Ausrichtung
    var loadImage = function (file) {
      if (window.createImageBitmap) {
        try {
          return createImageBitmap(file, { imageOrientation: 'from-image' })
            .then(function (bitmap) {
              return { source: bitmap, width: bitmap.width, height: bitmap.height, bitmap: bitmap };
            })
            .catch(function () { return loadViaElement(file); });
        } catch (e) {
          return loadViaElement(file);
        }
      }
      return loadViaElement(file);
    };

    var loadViaElement = function (file) {
      return new Promise(function (resolve, reject) {
        var url = URL.createObjectURL(file);
        var image = new Image();
        image.onload = function () {
          resolve({ source: image, width: image.naturalWidth, height: image.naturalHeight, url: url });
        };
        image.onerror = function () {
          URL.revokeObjectURL(url);
          reject(new Error('Bild nicht lesbar'));
        };
        image.src = url;
      });
    };

    var releaseImage = function (loaded) {
      if (loaded.bitmap && loaded.bitmap.close) {
        loaded.bitmap.close();
      }
      if (loaded.url) {
        URL.revokeObjectURL(loaded.url);
      }
    };

    /*
     * Kopiert eine Datei in den Arbeitsspeicher, damit beim Absenden nicht
     * erneut auf die Platte zugegriffen werden muss.
     */
    var toMemory = function (file) {
      if (!file.arrayBuffer) {
        return Promise.resolve(file);
      }
      return file.arrayBuffer().then(function (buffer) {
        try {
          return new File([buffer], file.name, { type: file.type, lastModified: file.lastModified });
        } catch (e) {
          return file;
        }
      }).catch(function () {
        return file;
      });
    };

    /*
     * Zielformat: Was der Server annimmt, bleibt erhalten. Alles andere –
     * vor allem HEIC von iPhones – wird zu JPEG, sonst lehnt der Server es ab.
     */
    var outputType = function (type) {
      return (type === 'image/png' || type === 'image/webp') ? type : 'image/jpeg';
    };

    var outputName = function (name, type) {
      var endung = type === 'image/png' ? 'png' : (type === 'image/webp' ? 'webp' : 'jpg');
      return name.replace(/\.[^.\\/]+$/, '') + '.' + endung;
    };

    /** Zeichnet das geladene Bild in der gewünschten Größe und gibt einen Blob zurück. */
    var render = function (loaded, edge, type, guete) {
      var longest = Math.max(loaded.width, loaded.height);
      var factor = Math.min(1, edge / longest);
      var width = Math.max(1, Math.round(loaded.width * factor));
      var height = Math.max(1, Math.round(loaded.height * factor));

      var canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      var context = canvas.getContext('2d');
      if (!context) {
        return Promise.resolve(null);
      }

      // JPEG kennt keine Transparenz – weißer Grund statt schwarz
      if (type === 'image/jpeg') {
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
      }
      context.drawImage(loaded.source, 0, 0, width, height);

      return new Promise(function (resolve) {
        try {
          canvas.toBlob(function (blob) { resolve(blob || null); }, type, guete);
        } catch (e) {
          resolve(null);
        }
      });
    };

    /*
     * Mehrere Stufen: Reicht die erste nicht unter das Serverlimit, wird
     * stärker verkleinert. So bleibt kein Bild hängen, nur weil es besonders
     * detailreich ist.
     */
    var stufen = function () {
      return [
        { edge: maxEdge, guete: quality },
        { edge: maxEdge, guete: Math.max(0.6, quality - 0.15) },
        { edge: Math.round(maxEdge * 0.75), guete: 0.75 },
        { edge: Math.round(maxEdge * 0.6), guete: 0.7 }
      ];
    };

    var scaleFile = function (file, zweiterVersuch) {
      var quelltyp = file.type || '';

      // Animierte GIFs bleiben unangetastet, sonst wäre die Animation weg
      if (quelltyp === 'image/gif') {
        return toMemory(file);
      }
      // Leerer Typ kommt bei manchen Android-Dateiauswahlen vor – dann trotzdem
      // versuchen; misslingt es, greift der Rückfall weiter unten.
      if (quelltyp !== '' && quelltyp.indexOf('image/') !== 0) {
        return toMemory(file);
      }

      var typ = outputType(quelltyp);
      var passt = quelltyp === typ;

      return loadImage(file).then(function (loaded) {
        if (!loaded.width || !loaded.height) {
          releaseImage(loaded);
          throw new Error('Bild ohne Maße');
        }

        // Klein genug und in einem Format, das der Server kennt: unverändert lassen
        if (passt
            && Math.max(loaded.width, loaded.height) <= maxEdge
            && (limit <= 0 || file.size <= limit)) {
          releaseImage(loaded);
          return toMemory(file);
        }

        return stufen().reduce(function (kette, stufe) {
          return kette.then(function (bisher) {
            if (bisher && limit > 0 && bisher.size <= limit) {
              return bisher; // schon gut genug
            }
            if (bisher && limit <= 0) {
              return bisher;
            }
            return render(loaded, stufe.edge, typ, stufe.guete).then(function (blob) {
              if (!blob) {
                return bisher;
              }
              return (!bisher || blob.size < bisher.size) ? blob : bisher;
            });
          });
        }, Promise.resolve(null)).then(function (blob) {
          releaseImage(loaded);

          if (!blob) {
            throw new Error('Umwandlung fehlgeschlagen');
          }
          // Nur übernehmen, wenn dabei wirklich etwas gespart wurde
          if (passt && blob.size >= file.size) {
            return toMemory(file);
          }
          try {
            return new File([blob], outputName(file.name, typ), { type: typ, lastModified: Date.now() });
          } catch (e) {
            return toMemory(file);
          }
        });
      }).catch(function () {
        /*
         * Auf Handys schlägt der erste Zugriff auf ein Bild aus der Galerie
         * gelegentlich fehl – etwa weil es noch aus der Cloud geladen wird
         * oder der Speicher gerade knapp ist. Einmal kurz warten und erneut
         * versuchen behebt genau das.
         */
        if (!zweiterVersuch) {
          return new Promise(function (weiter) { setTimeout(weiter, 400); })
            .then(function () { return scaleFile(file, true); });
        }
        return toMemory(file);
      });
    };

    // Nacheinander statt gleichzeitig, damit der Speicher nicht überläuft
    var prepareFiles = function (files) {
      var done = [];
      return files.reduce(function (chain, file) {
        return chain.then(function () {
          return scaleFile(file).then(function (result) { done.push(result); });
        });
      }, Promise.resolve()).then(function () { return done; });
    };

    /* Aufbereitung läuft direkt nach der Auswahl, nicht erst beim Absenden */
    var preparing = null;

    var startPreparation = function () {
      describeSelection();
      preparing = null;

      var original = Array.prototype.slice.call(input.files || []);
      if (original.length === 0 || !canSend) {
        return;
      }

      var vorher = original.reduce(function (sum, file) { return sum + file.size; }, 0);
      setNote('Bilder werden vorbereitet …', 'info');
      if (submit) {
        submit.disabled = true;
      }

      preparing = prepareFiles(original).then(function (files) {
        var nachher = files.reduce(function (sum, file) { return sum + file.size; }, 0);

        if (nachher < vorher * 0.98) {
          setNote('verkleinert auf ' + megabyte(nachher), 'ok');
        } else {
          setNote('');
        }

        var zuGross = files.filter(function (file) { return limit > 0 && file.size > limit; });
        if (zuGross.length > 0) {
          setNote(zuGross.length === 1
            ? '„' + zuGross[0].name + '“ bleibt auch verkleinert über ' + megabyte(limit)
              + ' und wird vom Server abgelehnt.'
            : zuGross.length + ' Bilder bleiben auch verkleinert über ' + megabyte(limit)
              + ' und werden vom Server abgelehnt.', 'error');
        }

        if (submit) {
          submit.disabled = false;
        }
        return files;
      }).catch(function () {
        setNote('');
        if (submit) {
          submit.disabled = false;
        }
        return original;
      });
    };

    input.addEventListener('change', startPreparation);

    if (cancel) {
      cancel.addEventListener('click', function () {
        input.value = '';
        preparing = null;
        describeSelection();
        setNote('');
        if (submit) {
          submit.disabled = false;
          submit.textContent = 'Hochladen';
        }
      });
    }

    /*
     * Versand per XMLHttpRequest statt als Formular: So bleibt bei einem
     * Abbruch die Seite stehen und zeigt eine verständliche Meldung, statt
     * dass der Browser auf eine eigene Fehlerseite wechselt.
     */
    var sendFiles = function (files) {
      var caption = form.querySelector('input[name="caption"]');
      var csrf = form.querySelector('input[name="csrf"]');

      var data = new FormData();
      data.append('csrf', csrf ? csrf.value : '');
      data.append('caption', caption ? caption.value : '');
      files.forEach(function (file) {
        data.append('files[]', file, file.name);
      });

      var request = new XMLHttpRequest();
      request.open('POST', form.getAttribute('action') || 'upload.php', true);
      request.setRequestHeader('Accept', 'application/json');

      if (request.upload) {
        request.upload.onprogress = function (event) {
          if (event.lengthComputable && submit) {
            submit.textContent = 'Wird hochgeladen … '
              + Math.round((event.loaded / event.total) * 100) + ' %';
          }
        };
      }

      var scheitern = function (meldung) {
        setNote(meldung, 'error');
        if (submit) {
          submit.disabled = false;
          submit.textContent = 'Erneut versuchen';
        }
      };

      request.onload = function () {
        if (request.status >= 200 && request.status < 400) {
          // Die Meldungen liegen als Flash in der Session und erscheinen dort
          window.location.href = 'index.php';
          return;
        }
        if (request.status === 400) {
          scheitern('Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
          return;
        }
        if (request.status === 413) {
          scheitern('Der Server hat die Datenmenge abgewiesen. Bitte weniger Bilder auf einmal wählen.');
          return;
        }
        scheitern('Der Upload ist fehlgeschlagen (Fehler ' + request.status + ').');
      };

      request.onerror = function () {
        scheitern('Die Verbindung wurde unterbrochen. Die Bilder sind noch ausgewählt – bitte erneut versuchen.');
      };

      request.onabort = function () {
        scheitern('Der Upload wurde abgebrochen.');
      };

      request.send(data);
    };

    form.addEventListener('submit', function (event) {
      if (!input.files || input.files.length === 0) {
        event.preventDefault();
        return;
      }

      if (!canSend) {
        // Ohne FormData/XHR bleibt es beim gewöhnlichen Formularversand
        if (submit) {
          submit.disabled = true;
          submit.textContent = 'Wird hochgeladen …';
        }
        return;
      }

      event.preventDefault();
      if (submit) {
        submit.disabled = true;
        submit.textContent = 'Wird hochgeladen …';
      }

      var bereit = preparing || Promise.resolve(Array.prototype.slice.call(input.files));
      bereit.then(sendFiles).catch(function () {
        sendFiles(Array.prototype.slice.call(input.files));
      });
    });

    /* Dateien lassen sich auf der ganzen Seite ablegen */
    if (overlay) {
      var dragDepth = 0;

      var carriesFiles = function (event) {
        var types = event.dataTransfer && event.dataTransfer.types;
        if (!types) {
          return false;
        }
        for (var i = 0; i < types.length; i++) {
          if (types[i] === 'Files') {
            return true;
          }
        }
        return false;
      };

      document.addEventListener('dragenter', function (event) {
        if (!carriesFiles(event)) {
          return;
        }
        dragDepth++;
        overlay.hidden = false;
      });

      document.addEventListener('dragover', function (event) {
        if (carriesFiles(event)) {
          event.preventDefault();
        }
      });

      document.addEventListener('dragleave', function (event) {
        if (!carriesFiles(event)) {
          return;
        }
        dragDepth--;
        if (dragDepth <= 0) {
          dragDepth = 0;
          overlay.hidden = true;
        }
      });

      document.addEventListener('drop', function (event) {
        if (!carriesFiles(event)) {
          return;
        }
        event.preventDefault();
        dragDepth = 0;
        overlay.hidden = true;

        try {
          input.files = event.dataTransfer.files;
          startPreparation();
        } catch (e) {
          /* ältere Browser: Auswahl über den Dialog */
        }
      });
    }
  }

  /* ---------------------------------------- Profilbild ------------- */

  var avatarInput = document.querySelector('[data-avatar-input]');
  if (avatarInput) {
    var avatarName = document.getElementById('avatar-name');
    var preview = document.querySelector('.profile-avatar .avatar');

    avatarInput.addEventListener('change', function () {
      var file = avatarInput.files && avatarInput.files[0];
      if (!file) {
        return;
      }

      if (avatarName) {
        avatarName.textContent = file.name;
      }

      if (!preview || !window.FileReader) {
        return;
      }

      var reader = new FileReader();
      reader.onload = function (event) {
        if (preview.tagName === 'IMG') {
          preview.src = event.target.result;
          return;
        }
        // Platzhalter mit Initiale durch das gewählte Bild ersetzen
        var image = document.createElement('img');
        image.className = preview.className.replace('avatar-fallback', '').replace(/\s+/g, ' ').trim();
        image.alt = '';
        image.src = event.target.result;
        preview.parentNode.replaceChild(image, preview);
        preview = image;
      };
      reader.readAsDataURL(file);
    });
  }

  /* ---------------------------------------- Benachrichtigungen ----- */

  var pushKarte = document.getElementById('push-card');
  if (pushKarte && swBereit) {
    var schalter = document.getElementById('push-toggle');
    var lage = document.getElementById('push-status');
    var zaehler = document.getElementById('push-devices');
    var vapid = pushKarte.getAttribute('data-vapid') || '';
    var csrf = pushKarte.getAttribute('data-csrf') || '';

    var melde = function (text, art) {
      if (!lage) {
        return;
      }
      lage.textContent = text || '';
      lage.className = 'push-status' + (art ? ' is-' + art : '');
      lage.hidden = !text;
    };

    // Der VAPID-Schlüssel muss dem Browser als Bytefolge übergeben werden
    var schluesselBytes = function (base64url) {
      var text = (base64url + '===').slice(0, base64url.length + (4 - base64url.length % 4) % 4);
      var roh = window.atob(text.replace(/-/g, '+').replace(/_/g, '/'));
      var bytes = new Uint8Array(roh.length);
      for (var i = 0; i < roh.length; i++) {
        bytes[i] = roh.charCodeAt(i);
      }
      return bytes;
    };

    var anServer = function (nutzlast) {
      return fetch('push-subscribe.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nutzlast)
      }).then(function (antwort) {
        return antwort.json();
      });
    };

    var moeglich = 'PushManager' in window && 'Notification' in window && vapid !== '';

    if (!moeglich) {
      // Auf iOS gibt es PushManager nur in der vom Home-Bildschirm gestarteten App
      melde('Dieser Browser kann keine Benachrichtigungen empfangen. '
        + 'Auf dem iPhone die Seite über „Teilen → Zum Home-Bildschirm" ablegen und von dort öffnen.', 'info');
    } else if (Notification.permission === 'denied') {
      melde('Benachrichtigungen sind für diese Seite in den Browsereinstellungen blockiert.', 'error');
    } else {
      swBereit.then(function () {
        return navigator.serviceWorker.ready;
      }).then(function (registrierung) {
        return registrierung.pushManager.getSubscription().then(function (abo) {
          if (!schalter) {
            return;
          }
          schalter.hidden = false;

          var zeichne = function (an) {
            schalter.textContent = an ? 'Auf diesem Gerät ausschalten' : 'Auf diesem Gerät einschalten';
            schalter.className = an ? 'btn' : 'btn btn-primary';
            if (an) {
              melde('Dieses Gerät ist angemeldet.', 'ok');
            } else {
              melde('');
            }
          };

          zeichne(!!abo);

          schalter.addEventListener('click', function () {
            schalter.disabled = true;

            registrierung.pushManager.getSubscription().then(function (vorhanden) {
              if (vorhanden) {
                // Abmelden
                var endpunkt = vorhanden.endpoint;
                return vorhanden.unsubscribe().then(function () {
                  return anServer({ action: 'unsubscribe', endpoint: endpunkt, csrf: csrf });
                }).then(function () {
                  zeichne(false);
                  if (zaehler) {
                    zaehler.textContent = Math.max(0, parseInt(zaehler.textContent, 10) - 1);
                  }
                });
              }

              // Anmelden – fragt beim ersten Mal um Erlaubnis
              return Notification.requestPermission().then(function (erlaubnis) {
                if (erlaubnis !== 'granted') {
                  melde('Ohne Erlaubnis geht es nicht. Du kannst sie in den Browsereinstellungen nachträglich erteilen.', 'error');
                  return null;
                }
                return registrierung.pushManager.subscribe({
                  userVisibleOnly: true,
                  applicationServerKey: schluesselBytes(vapid)
                });
              }).then(function (abo) {
                if (!abo) {
                  return null;
                }
                var daten = abo.toJSON();
                return anServer({
                  action: 'subscribe',
                  endpoint: abo.endpoint,
                  p256dh: daten.keys ? daten.keys.p256dh : '',
                  auth: daten.keys ? daten.keys.auth : '',
                  csrf: csrf
                }).then(function (antwort) {
                  if (!antwort || !antwort.ok) {
                    throw new Error('Server hat abgelehnt');
                  }
                  zeichne(true);
                  if (zaehler) {
                    zaehler.textContent = parseInt(zaehler.textContent, 10) + 1;
                  }
                });
              });
            }).catch(function () {
              melde('Das hat nicht geklappt. Bitte später erneut versuchen.', 'error');
            }).then(function () {
              schalter.disabled = false;
            });
          });
        });
      }).catch(function () {
        melde('Der Hintergrunddienst konnte nicht gestartet werden.', 'error');
      });
    }
  }

  /* ---------------------------------------- Likes ------------------ */

  var canFetch = !!(window.fetch && window.FormData);

  var paintLike = function (button, liked, count) {
    button.classList.toggle('is-liked', liked);
    button.setAttribute('aria-pressed', liked ? 'true' : 'false');
    var counter = button.querySelector('.like-count');
    if (counter) {
      counter.textContent = count;
    }
  };

  /* Schickt den Like ab und gibt den neuen Stand zurück. */
  var sendLike = function (likeForm) {
    var button = likeForm.querySelector('.like-btn');
    if (!button || button.disabled) {
      return Promise.resolve(null);
    }

    button.disabled = true;
    button.classList.add('is-busy');

    return fetch(likeForm.action, {
      method: 'POST',
      body: new FormData(likeForm),
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Anfrage fehlgeschlagen');
      }
      return response.json();
    }).then(function (data) {
      paintLike(button, !!data.liked, data.count);
      button.removeAttribute('title');
      return data;
    }).catch(function () {
      // Im Zweifel neu laden, damit der angezeigte Stand wieder stimmt
      window.location.reload();
      return null;
    }).then(function (data) {
      button.disabled = false;
      button.classList.remove('is-busy');
      return data;
    });
  };

  var likeForms = Array.prototype.slice.call(document.querySelectorAll('[data-like]'));
  likeForms.forEach(function (likeForm) {
    likeForm.addEventListener('submit', function (event) {
      if (!canFetch) {
        return; // ohne fetch: normales Formular, Seite lädt neu
      }
      event.preventDefault();
      sendLike(likeForm);
    });
  });

  /* ---------------------------------------- Stammtisch ------------- */

  /*
   * Vorschau des verknüpften Bildes. Ohne JavaScript bleibt es bei der
   * Auswahlliste – zum Speichern reicht die völlig aus.
   */
  var bildAuswahl = document.querySelector('[data-image-picker]');
  var bildVorschau = document.getElementById('meal-preview');

  if (bildAuswahl && bildVorschau) {
    var vorschauBild = bildVorschau.querySelector('img');

    var zeigeVorschau = function () {
      var option = bildAuswahl.options[bildAuswahl.selectedIndex];
      var quelle = option ? option.getAttribute('data-thumb') : null;

      if (quelle) {
        vorschauBild.src = quelle;
        bildVorschau.hidden = false;
      } else {
        vorschauBild.removeAttribute('src');
        bildVorschau.hidden = true;
      }
    };

    bildAuswahl.addEventListener('change', zeigeVorschau);
    zeigeVorschau();
  }

  /* ---------------------------------------- Lightbox --------------- */

  var lightbox = document.getElementById('lightbox');
  if (!lightbox) {
    return;
  }

  var lbImage = document.getElementById('lb-image');
  var lbCaption = document.getElementById('lb-caption');
  var lbLike = document.getElementById('lb-like');
  var links = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox]'));
  var current = 0;

  var tileLikeForm = function (index) {
    var tile = links[index] ? links[index].closest('.tile') : null;
    return tile ? tile.querySelector('[data-like]') : null;
  };

  var syncLightboxLike = function () {
    if (!lbLike) {
      return;
    }
    var likeForm = tileLikeForm(current);
    var source = likeForm ? likeForm.querySelector('.like-btn') : null;
    if (!source) {
      lbLike.hidden = true;
      return;
    }
    lbLike.hidden = false;
    paintLike(
      lbLike,
      source.classList.contains('is-liked'),
      source.querySelector('.like-count').textContent
    );
  };

  var show = function (index) {
    if (index < 0) {
      index = links.length - 1;
    }
    if (index >= links.length) {
      index = 0;
    }
    current = index;

    var link = links[index];
    lbImage.src = link.getAttribute('data-full');
    lbImage.alt = link.getAttribute('data-caption') || '';

    var caption = link.getAttribute('data-caption');
    var meta = link.getAttribute('data-meta') || '';
    lbCaption.textContent = caption ? caption + ' — ' + meta : meta;

    syncLightboxLike();

    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
  };

  var close = function () {
    lightbox.hidden = true;
    lbImage.src = '';
    document.body.style.overflow = '';
  };

  links.forEach(function (link, index) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      show(index);
    });
  });

  if (lbLike) {
    lbLike.addEventListener('click', function (event) {
      event.stopPropagation();
      var likeForm = tileLikeForm(current);
      if (!likeForm) {
        return;
      }
      if (!canFetch) {
        likeForm.submit();
        return;
      }
      sendLike(likeForm).then(syncLightboxLike);
    });
  }

  document.getElementById('lb-close').addEventListener('click', close);
  document.getElementById('lb-prev').addEventListener('click', function (e) {
    e.stopPropagation();
    show(current - 1);
  });
  document.getElementById('lb-next').addEventListener('click', function (e) {
    e.stopPropagation();
    show(current + 1);
  });

  lightbox.addEventListener('click', function (event) {
    if (event.target === lightbox || event.target === lbImage.parentNode) {
      close();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (lightbox.hidden) {
      return;
    }
    if (event.key === 'Escape') {
      close();
    } else if (event.key === 'ArrowLeft') {
      show(current - 1);
    } else if (event.key === 'ArrowRight') {
      show(current + 1);
    }
  });

  /* Wischgesten am Handy */
  var touchX = null;
  var touchY = null;

  lightbox.addEventListener('touchstart', function (event) {
    if (event.touches.length !== 1) {
      touchX = null;
      return;
    }
    touchX = event.touches[0].clientX;
    touchY = event.touches[0].clientY;
  }, { passive: true });

  lightbox.addEventListener('touchend', function (event) {
    if (touchX === null || !event.changedTouches.length) {
      return;
    }
    var dx = event.changedTouches[0].clientX - touchX;
    var dy = event.changedTouches[0].clientY - touchY;
    touchX = null;

    if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) {
      return;
    }
    show(dx < 0 ? current + 1 : current - 1);
  }, { passive: true });
})();

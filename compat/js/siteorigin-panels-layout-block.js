"use strict";

function _slicedToArray(arr, i) { return _arrayWithHoles(arr) || _iterableToArrayLimit(arr, i) || _nonIterableRest(); }

function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance"); }

function _iterableToArrayLimit(arr, i) { if (!(Symbol.iterator in Object(arr) || Object.prototype.toString.call(arr) === "[object Arguments]")) { return; } var _arr = []; var _n = true; var _d = false; var _e = undefined; try { for (var _i = arr[Symbol.iterator](), _s; !(_n = (_s = _i.next()).done); _n = true) { _arr.push(_s.value); if (i && _arr.length === i) break; } } catch (err) { _d = true; _e = err; } finally { try { if (!_n && _i["return"] != null) _i["return"](); } finally { if (_d) throw _e; } } return _arr; }

function _arrayWithHoles(arr) { if (Array.isArray(arr)) return arr; }

function _typeof(obj) { "@babel/helpers - typeof"; if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

/**
 * Checks if two panels data objects are equal.
 * @param {Object} newPanelsData - The new panels data object.
 * @param {Object} oldPanelsData - The old panels data object.
 * @returns {boolean} - Returns true if the two panels data objects are equal, otherwise false.
 */
function SiteOriginIsPanelsEqual(newPanelsData, oldPanelsData) {
  if (newPanelsData === oldPanelsData) {
    return true;
  }

  if (!newPanelsData || !oldPanelsData) {
    return newPanelsData === oldPanelsData;
  } // If neither newPanelsData nor oldPanelsData are objects, assume they're not the same.


  if (_typeof(newPanelsData) !== 'object' || _typeof(oldPanelsData) !== 'object') {
    return false;
  }

  var keys = Object.keys(newPanelsData);

  if (keys.length !== Object.keys(oldPanelsData).length) {
    return false;
  }

  return keys.every(function (k) {
    return SiteOriginIsPanelsEqual(newPanelsData[k], oldPanelsData[k]);
  });
}

function SiteOriginPanelsLayoutBlock(props) {
  var editing = props.editing,
      panelsData = props.panelsData,
      onContentChange = props.onContentChange,
      onRowOrWidgetMouseDown = props.onRowOrWidgetMouseDown,
      onRowOrWidgetMouseUp = props.onRowOrWidgetMouseUp; // State

  var _wp$element$useState = wp.element.useState(true),
      _wp$element$useState2 = _slicedToArray(_wp$element$useState, 2),
      loadingPreview = _wp$element$useState2[0],
      setLoadingPreview = _wp$element$useState2[1];

  var _wp$element$useState3 = wp.element.useState(''),
      _wp$element$useState4 = _slicedToArray(_wp$element$useState3, 2),
      previewHtml = _wp$element$useState4[0],
      setPreviewHtml = _wp$element$useState4[1];

  var _wp$element$useState5 = wp.element.useState(!editing),
      _wp$element$useState6 = _slicedToArray(_wp$element$useState5, 2),
      previewInitialized = _wp$element$useState6[0],
      setPreviewInitialized = _wp$element$useState6[1];

  var _wp$element$useState7 = wp.element.useState(false),
      _wp$element$useState8 = _slicedToArray(_wp$element$useState7, 2),
      pendingPreviewRequest = _wp$element$useState8[0],
      setPendingPreviewRequest = _wp$element$useState8[1];

  var _wp$element$useState9 = wp.element.useState(false),
      _wp$element$useState10 = _slicedToArray(_wp$element$useState9, 2),
      panelsInitialized = _wp$element$useState10[0],
      setPanelsInitialized = _wp$element$useState10[1]; // DOM and instance refs


  var panelsContainer = wp.element.useRef(null);
  var previewContainer = wp.element.useRef(null);
  var builderViewRef = wp.element.useRef(null);
  var fetchPreviewTimer = wp.element.useRef(null);
  var currentFetchRequest = wp.element.useRef(null);
  var isMountedRef = wp.element.useRef(false); // Keep prop callbacks current so the mount-only setup effect always calls the latest version.

  var onContentChangeRef = wp.element.useRef(onContentChange);
  var onRowOrWidgetMouseDownRef = wp.element.useRef(onRowOrWidgetMouseDown);
  var onRowOrWidgetMouseUpRef = wp.element.useRef(onRowOrWidgetMouseUp);
  wp.element.useEffect(function () {
    onContentChangeRef.current = onContentChange;
  }, [onContentChange]);
  wp.element.useEffect(function () {
    onRowOrWidgetMouseDownRef.current = onRowOrWidgetMouseDown;
  }, [onRowOrWidgetMouseDown]);
  wp.element.useEffect(function () {
    onRowOrWidgetMouseUpRef.current = onRowOrWidgetMouseUp;
  }, [onRowOrWidgetMouseUp]); // Fetch a preview from the server and update state.

  var fetchPreview = wp.element.useCallback(function () {
    if (!isMountedRef.current) {
      return;
    }

    setPreviewInitialized(false); // Capture the iframe document now so the deferred trigger uses the correct context.

    var iframeDoc = panelsContainer.current ? panelsContainer.current.ownerDocument : document;
    var fetchRequest = jQuery.post({
      url: window.soPanelsBlockEditorAdmin.previewUrl,
      data: {
        action: 'so_panels_layout_block_preview',
        panelsData: JSON.stringify(builderViewRef.current.getData())
      }
    }).then(function (preview) {
      if (!isMountedRef.current) {
        return;
      } // Wait until previewHTML has finished updating to cut
      // down on the chance of nothing being rendered.


      setTimeout(function () {
        jQuery(iframeDoc).trigger('panels_setup_preview');
      }, 1000);

      if (fetchRequest === currentFetchRequest.current && preview) {
        setPreviewHtml(preview);
        setLoadingPreview(false);
        setPreviewInitialized(false);
        setPendingPreviewRequest(false);
      }
    });
    currentFetchRequest.current = fetchRequest;
    return fetchRequest;
  }, []); // Setup the panels builder on mount; tear it down on unmount.

  wp.element.useEffect(function () {
    isMountedRef.current = true; // Resolve iframe document and whether script/content run inside an iframe.

    var iframeDoc = panelsContainer.current.ownerDocument;
    var isScriptInIframe = window.self !== window.top;
    var isContentInIframe = iframeDoc !== window.document;
    var soDocument = iframeDoc; // Block native HTML5 dragstart so the Block Editor doesn't intercept panel drags.

    var onContainerDragStart = function onContainerDragStart(e) {
      e.stopPropagation();
      e.preventDefault();
    };

    panelsContainer.current.addEventListener('dragstart', onContainerDragStart);
    var $panelsContainer = jQuery(panelsContainer.current);
    var config = {
      editorType: 'standalone',
      loadLiveEditor: false,
      postId: window.soPanelsBlockEditorAdmin.postId,
      editorPreview: window.soPanelsBlockEditorAdmin.liveEditor
    };
    var builderModel = new panels.model.builder();
    builderViewRef.current = new panels.view.builder({
      model: builderModel,
      config: config
    }); // Make sure panelsData is defined and clone so that we don't alter the underlying attribute.

    var initialPanelsData = JSON.parse(JSON.stringify(jQuery.extend({}, panelsData))); // Disable block selection while dragging rows or widgets.

    var rowOrWidgetMouseDown = function rowOrWidgetMouseDown(e) {
      // toggleSelection(false) tells the block editor to not start its own drag-selection
      // handling. Do NOT stopPropagation here — jQuery UI sortable binds its mousedown
      // handler on the sortable container (an ancestor), so stopping propagation would
      // prevent jQuery UI from ever seeing the event and starting the drag.
      if (typeof onRowOrWidgetMouseDownRef.current === 'function') {
        onRowOrWidgetMouseDownRef.current();
      }

      var rowOrWidgetMouseUp = function rowOrWidgetMouseUp() {
        jQuery(soDocument).off('mouseup', rowOrWidgetMouseUp);

        if (typeof onRowOrWidgetMouseUpRef.current === 'function') {
          onRowOrWidgetMouseUpRef.current();
        }
      };

      jQuery(soDocument).on('mouseup', rowOrWidgetMouseUp);
    };

    builderViewRef.current.on('row_added', function () {
      builderViewRef.current.$('.so-row-move').off('mousedown', rowOrWidgetMouseDown);
      builderViewRef.current.$('.so-row-move').on('mousedown', rowOrWidgetMouseDown);
      builderViewRef.current.$('.so-widget').off('mousedown', rowOrWidgetMouseDown);
      builderViewRef.current.$('.so-widget').on('mousedown', rowOrWidgetMouseDown);
    });
    builderViewRef.current.on('widget_added', function () {
      builderViewRef.current.$('.so-widget').off('mousedown', rowOrWidgetMouseDown);
      builderViewRef.current.$('.so-widget').on('mousedown', rowOrWidgetMouseDown);
    });
    builderViewRef.current.render().attach({
      container: $panelsContainer
    }).setData(initialPanelsData);
    builderViewRef.current.trigger('builder_resize'); // Re-fire builder_resize after iframe layout has actually settled so that
    // resizeRow() measures cell heights against a stable layout instead of the
    // natural unstyled stack height.

    var settleResize = function settleResize() {
      if (builderViewRef.current) {
        builderViewRef.current.trigger('builder_resize');
      }
    }; // Re-fire once the iframe document is fully loaded.


    if (iframeDoc.readyState === 'complete') {
      requestAnimationFrame(function () {
        return requestAnimationFrame(settleResize);
      });
    } else {
      var onIframeReady = function onIframeReady() {
        if (iframeDoc.readyState === 'complete') {
          iframeDoc.removeEventListener('readystatechange', onIframeReady);
          requestAnimationFrame(function () {
            return requestAnimationFrame(settleResize);
          });
        }
      };

      iframeDoc.addEventListener('readystatechange', onIframeReady);
    } // Re-fire once web fonts have loaded (font swaps change widget heights).


    if (iframeDoc.fonts && iframeDoc.fonts.ready && typeof iframeDoc.fonts.ready.then === 'function') {
      iframeDoc.fonts.ready.then(settleResize)["catch"](function () {});
    }

    builderViewRef.current.on('content_change', function () {
      var newPanelsData = builderViewRef.current.getData();

      if (!SiteOriginIsPanelsEqual(initialPanelsData, newPanelsData)) {
        if (typeof onContentChangeRef.current === 'function') {
          var pendingContentChange = onContentChangeRef.current(newPanelsData);

          if (pendingContentChange && typeof pendingContentChange.then === 'function') {
            builderViewRef.current.pendingContentChange = pendingContentChange;
            pendingContentChange["finally"](function () {
              if (builderViewRef.current && builderViewRef.current.pendingContentChange === pendingContentChange) {
                builderViewRef.current.pendingContentChange = null;
              }
            });
          }
        }

        setLoadingPreview(true);
        setPreviewHtml('');
      } // Widget previews can re-render on content_change; re-measure after the next layout.


      requestAnimationFrame(function () {
        if (builderViewRef.current) {
          builderViewRef.current.trigger('builder_resize');
        }
      });
    }); // Use iframeDoc so panels scripts inside the iframe receive the setup event.

    jQuery(iframeDoc).trigger('panels_setup', builderViewRef.current);

    if (typeof window.soPanelsBuilderView === 'undefined') {
      window.soPanelsBuilderView = [];
    }

    window.soPanelsBuilderView.push(builderViewRef.current); // If in an iframe, patch jQuery UI instances so their document/window use iframeDoc.

    if (isContentInIframe || isScriptInIframe) {
      var iframeWindow = iframeDoc.defaultView;

      var patchJQueryUIDocuments = function patchJQueryUIDocuments() {
        if (!builderViewRef.current) {
          return;
        }

        builderViewRef.current.$('.so-rows-container, .widgets-container').each(function () {
          var inst = jQuery(this).sortable('instance');

          if (inst && inst.document && inst.document[0] !== iframeDoc) {
            inst.document = jQuery(iframeDoc);
            inst.window = jQuery(iframeWindow);
          }
        });
        builderViewRef.current.$('.resize-handle').each(function () {
          var inst = jQuery(this).draggable('instance');

          if (inst && inst.document && inst.document[0] !== iframeDoc) {
            inst.document = jQuery(iframeDoc);
            inst.window = jQuery(iframeWindow);
          }
        });
      }; // Patch initial instances after first render.


      setTimeout(patchJQueryUIDocuments, 0); // Re-patch whenever a new row or widget is added (new instances are created).

      builderViewRef.current.on('row_added widget_added content_change', patchJQueryUIDocuments);
    }

    setPanelsInitialized(true);
    return function () {
      isMountedRef.current = false;

      if (panelsContainer.current) {
        panelsContainer.current.removeEventListener('dragstart', onContainerDragStart);
      }

      if (builderViewRef.current) {
        // Remove builder from global builder list.
        if (typeof window.soPanelsBuilderView !== 'undefined') {
          window.soPanelsBuilderView = window.soPanelsBuilderView.filter(function (view) {
            return view !== builderViewRef.current;
          });
        }

        builderViewRef.current.remove();
        builderViewRef.current = null;
      }

      if (currentFetchRequest.current && typeof currentFetchRequest.current.abort === 'function') {
        currentFetchRequest.current.abort();
      }

      clearTimeout(fetchPreviewTimer.current);

      if (panelsContainer.current) {
        jQuery(panelsContainer.current).empty();
      }

      if (previewContainer.current) {
        jQuery(previewContainer.current).empty();
      }
    };
  }, []); // Schedule a preview fetch or fire setup when preview loading state changes.

  wp.element.useEffect(function () {
    if (!panelsInitialized) {
      return;
    }

    if (loadingPreview) {
      if (!pendingPreviewRequest) {
        setPendingPreviewRequest(true);
        clearTimeout(fetchPreviewTimer.current);
        fetchPreviewTimer.current = setTimeout(function () {
          return fetchPreview();
        }, 1000);
      }
    } else if (!previewInitialized) {
      var iframeDoc = panelsContainer.current ? panelsContainer.current.ownerDocument : document;
      jQuery(iframeDoc).trigger('panels_setup_preview');
      setPreviewInitialized(true);
    }
  }, [loadingPreview, panelsInitialized, pendingPreviewRequest, previewInitialized, fetchPreview]); // Trigger a layout recalculation whenever we switch back into edit mode.

  wp.element.useEffect(function () {
    if (editing && builderViewRef.current) {
      builderViewRef.current.menu.setContext({
        container: jQuery(panelsContainer.current)
      });
      setTimeout(function () {
        if (builderViewRef.current) {
          builderViewRef.current.trigger('builder_resize');
        }
      });
    }
  }, [editing]);
  return React.createElement(wp.element.Fragment, null, React.createElement("div", {
    key: "layout-block",
    className: "siteorigin-panels-layout-block-container",
    ref: panelsContainer,
    hidden: !editing
  }), React.createElement("div", {
    key: "preview",
    className: "so-panels-block-layout-preview-container",
    hidden: editing
  }, loadingPreview ? React.createElement("div", {
    className: "so-panels-spinner-container"
  }, React.createElement("span", null, React.createElement(wp.components.Spinner, null))) : React.createElement("div", {
    className: "so-panels-raw-html-container",
    ref: previewContainer
  }, React.createElement(wp.element.RawHTML, null, previewHtml))));
}

var hasLayoutCategory = wp.blocks.getCategories().some(function (category) {
  return category.slug === 'layout';
});
wp.blocks.registerBlockType('siteorigin-panels/layout-block', {
  title: wp.i18n.__('SiteOrigin Layout', 'siteorigin-panels'),
  description: wp.i18n.__("Build a layout using SiteOrigin's Page Builder.", 'siteorigin-panels'),
  icon: function icon() {
    return React.createElement("span", {
      className: "siteorigin-panels-block-icon"
    });
  },
  category: hasLayoutCategory ? 'layout' : 'design',
  keywords: ['page builder', 'column,grid', 'panel'],
  supports: {
    html: false
  },
  attributes: {
    panelsData: {
      type: 'object'
    },
    contentPreview: {
      type: 'string'
    }
  },
  apiVersion: 3,
  edit: function edit(_ref) {
    var attributes = _ref.attributes,
        setAttributes = _ref.setAttributes,
        toggleSelection = _ref.toggleSelection;
    var blockProps = wp.blockEditor.useBlockProps(); // Derive the initial editing state once.

    var hasPanelsData = attributes.panelsData && _typeof(attributes.panelsData) === 'object' && Object.keys(attributes.panelsData).length > 0;
    var initialEditing = hasPanelsData ? window.soPanelsBlockEditorAdmin.defaultMode === 'edit' : true;

    var _wp$element$useState11 = wp.element.useState(initialEditing),
        _wp$element$useState12 = _slicedToArray(_wp$element$useState11, 2),
        editing = _wp$element$useState12[0],
        setEditing = _wp$element$useState12[1];

    var switchToEditing = wp.element.useCallback(function () {
      setEditing(true);
    }, []);
    var switchToPreview = wp.element.useCallback(function () {
      if (attributes.panelsData) {
        setEditing(false);
      }
    }, [attributes.panelsData]);
    var onLayoutBlockContentChange = wp.element.useCallback(function (newPanelsData) {
      if (newPanelsData.widgets !== null && _typeof(newPanelsData.widgets) === 'object' && Object.keys(newPanelsData.widgets).length > 0) {
        // Send panelsData to server for sanitization.
        var isNewWPBlockEditor = jQuery('.widgets-php').length;

        if (!isNewWPBlockEditor) {
          wp.data.dispatch('core/editor').lockPostSaving();
        }

        return new Promise(function (resolve, reject) {
          jQuery.post(panelsOptions.ajaxurl, {
            action: 'so_panels_builder_content_json',
            panels_data: JSON.stringify(newPanelsData),
            post_id: !isNewWPBlockEditor ? wp.data.select("core/editor").getCurrentPostId() : ''
          }).done(function (content) {
            var panelsAttributes = {};

            if (content.sanitized_panels_data !== '') {
              panelsAttributes.panelsData = content.sanitized_panels_data;
            }

            if (content.preview !== '') {
              panelsAttributes.contentPreview = content.preview;
            }

            setAttributes({
              contentPreview: panelsAttributes.contentPreview,
              panelsData: panelsAttributes.panelsData,
              previewInitialized: false
            });
            setTimeout(function () {
              if (!isNewWPBlockEditor) {
                wp.data.dispatch('core/editor').unlockPostSaving();
              }

              resolve(content);
            }, 0);
          }).fail(function (jqXHR, textStatus, errorThrown) {
            if (!isNewWPBlockEditor) {
              wp.data.dispatch('core/editor').unlockPostSaving();
            }

            reject(errorThrown || textStatus);
          });
        });
      }

      setAttributes({
        panelsData: null,
        contentPreview: null
      });
      return Promise.resolve();
    }, [setAttributes]);
    var disableSelection = wp.element.useCallback(function () {
      toggleSelection(false);
    }, [toggleSelection]);
    var enableSelection = wp.element.useCallback(function () {
      toggleSelection(true);
    }, [toggleSelection]);
    return React.createElement(wp.element.Fragment, null, React.createElement(wp.blockEditor.BlockControls, null, React.createElement(wp.components.ToolbarGroup, {
      label: wp.i18n.__('Page Builder Mode Controls', 'siteorigin-panels')
    }, editing ? React.createElement(wp.components.ToolbarButton, {
      icon: "visibility",
      label: wp.i18n.__('Preview layout.', 'siteorigin-panels'),
      onClick: switchToPreview
    }) : React.createElement(wp.components.ToolbarButton, {
      icon: "edit",
      label: wp.i18n.__('Edit layout.', 'siteorigin-panels'),
      onClick: switchToEditing
    }))), React.createElement("div", blockProps, React.createElement(SiteOriginPanelsLayoutBlock, {
      editing: editing,
      panelsData: attributes.panelsData,
      onContentChange: onLayoutBlockContentChange,
      onRowOrWidgetMouseDown: disableSelection,
      onRowOrWidgetMouseUp: enableSelection
    })));
  }
});
jQuery(function () {
  var isEditorReady = function isEditorReady() {
    var editorState = false;

    if (wp.data.select('core/block-editor')) {
      editorState = wp.data.select('core/block-editor').hasInserterItems();
    } else if (wp.data.select('core/editor')) {
      editorState = wp.data.select('core/editor').__unstableIsEditorReady();
    }

    return editorState;
  }; // Resolve Block Editor warning for SO Layout Block.


  var unsubscribe = null;
  unsubscribe = wp.data.subscribe(function () {
    if (isEditorReady() && unsubscribe) {
      unsubscribe();
      setTimeout(function () {
        jQuery('.wp-block[data-type="siteorigin-panels/layout-block"].has-warning .block-editor-warning__action .components-button').trigger('click');
      }, 250);
    }
  }); // It's possible the above attempt may fail.
  // So to prevent a situation where the button will still appear,
  // do an additional check every 1.5s until it's unlikely there are
  // any buttons are present.

  var checkInterval = setInterval(function () {
    if (isEditorReady()) {
      return;
    }

    jQuery('.wp-block[data-type="siteorigin-panels/layout-block"].has-warning .block-editor-warning__action .components-button').trigger('click');
    clearInterval(checkInterval);
  }, 1500);

  if (window.soPanelsBlockEditorAdmin.showAddButton) {
    jQuery(function () {
      setTimeout(function () {
        var editorDispatch = wp.data.dispatch('core/editor');
        var editorSelect = wp.data.select('core/editor');
        var tmpl = jQuery('#siteorigin-panels-add-layout-block-button').html();

        if (jQuery('.block-editor-writing-flow > .block-editor-block-list__layout').length) {
          // > WP 5.7
          var buttonSelector = '.block-editor-writing-flow > .block-editor-block-list__layout';
        } else {
          // < WP 5.7
          var buttonSelector = '.editor-writing-flow > div:first, .block-editor-writing-flow > div:not([tabindex])';
        }

        var $addButton = jQuery(tmpl).appendTo(buttonSelector);
        $addButton.on('click', function () {
          var layoutBlock = wp.blocks.createBlock('siteorigin-panels/layout-block', {});
          var isEmpty = editorSelect.isEditedPostEmpty();

          if (isEmpty) {
            var blocks = editorSelect.getBlocks();

            if (blocks.length) {
              editorDispatch.replaceBlock(blocks[0].clientId, layoutBlock);
            } else {
              editorDispatch.insertBlock(layoutBlock);
            }
          } else {
            editorDispatch.insertBlock(layoutBlock);
          }
        });

        var hideButtonIfBlocks = function hideButtonIfBlocks() {
          var isEmpty = wp.data.select('core/editor').isEditedPostEmpty();

          if (isEmpty) {
            $addButton.show();
          } else {
            $addButton.hide();
          }
        };

        wp.data.subscribe(hideButtonIfBlocks);
        hideButtonIfBlocks();
      }, 100);
    });
  }
}); // Detect preview mode changes, and trigger resize.

jQuery(document).on('click', '.block-editor-post-preview__button-resize', function (e) {
  if (!jQuery(this).hasClass('has-icon')) {
    jQuery(window).trigger('resize');
  }
});

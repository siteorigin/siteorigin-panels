"use strict";

function _typeof(obj) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (obj) { return typeof obj; } : function (obj) { return obj && "function" == typeof Symbol && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }, _typeof(obj); }
function _classCallCheck(instance, Constructor) { if (!(instance instanceof Constructor)) { throw new TypeError("Cannot call a class as a function"); } }
function _defineProperties(target, props) { for (var i = 0; i < props.length; i++) { var descriptor = props[i]; descriptor.enumerable = descriptor.enumerable || false; descriptor.configurable = true; if ("value" in descriptor) descriptor.writable = true; Object.defineProperty(target, _toPropertyKey(descriptor.key), descriptor); } }
function _createClass(Constructor, protoProps, staticProps) { if (protoProps) _defineProperties(Constructor.prototype, protoProps); if (staticProps) _defineProperties(Constructor, staticProps); Object.defineProperty(Constructor, "prototype", { writable: false }); return Constructor; }
function _toPropertyKey(arg) { var key = _toPrimitive(arg, "string"); return _typeof(key) === "symbol" ? key : String(key); }
function _toPrimitive(input, hint) { if (_typeof(input) !== "object" || input === null) return input; var prim = input[Symbol.toPrimitive]; if (prim !== undefined) { var res = prim.call(input, hint || "default"); if (_typeof(res) !== "object") return res; throw new TypeError("@@toPrimitive must return a primitive value."); } return (hint === "string" ? String : Number)(input); }
function _inherits(subClass, superClass) { if (typeof superClass !== "function" && superClass !== null) { throw new TypeError("Super expression must either be null or a function"); } subClass.prototype = Object.create(superClass && superClass.prototype, { constructor: { value: subClass, writable: true, configurable: true } }); Object.defineProperty(subClass, "prototype", { writable: false }); if (superClass) _setPrototypeOf(subClass, superClass); }
function _setPrototypeOf(o, p) { _setPrototypeOf = Object.setPrototypeOf ? Object.setPrototypeOf.bind() : function _setPrototypeOf(o, p) { o.__proto__ = p; return o; }; return _setPrototypeOf(o, p); }
function _createSuper(Derived) { var hasNativeReflectConstruct = _isNativeReflectConstruct(); return function _createSuperInternal() { var Super = _getPrototypeOf(Derived), result; if (hasNativeReflectConstruct) { var NewTarget = _getPrototypeOf(this).constructor; result = Reflect.construct(Super, arguments, NewTarget); } else { result = Super.apply(this, arguments); } return _possibleConstructorReturn(this, result); }; }
function _possibleConstructorReturn(self, call) { if (call && (_typeof(call) === "object" || typeof call === "function")) { return call; } else if (call !== void 0) { throw new TypeError("Derived constructors may only return object or undefined"); } return _assertThisInitialized(self); }
function _assertThisInitialized(self) { if (self === void 0) { throw new ReferenceError("this hasn't been initialised - super() hasn't been called"); } return self; }
function _isNativeReflectConstruct() { if (typeof Reflect === "undefined" || !Reflect.construct) return false; if (Reflect.construct.sham) return false; if (typeof Proxy === "function") return true; try { Boolean.prototype.valueOf.call(Reflect.construct(Boolean, [], function () {})); return true; } catch (e) { return false; } }
function _getPrototypeOf(o) { _getPrototypeOf = Object.setPrototypeOf ? Object.getPrototypeOf.bind() : function _getPrototypeOf(o) { return o.__proto__ || Object.getPrototypeOf(o); }; return _getPrototypeOf(o); }
var SiteOriginPanelsLayoutBlock = /*#__PURE__*/function (_wp$element$Component) {
  _inherits(SiteOriginPanelsLayoutBlock, _wp$element$Component);
  var _super = _createSuper(SiteOriginPanelsLayoutBlock);
  function SiteOriginPanelsLayoutBlock(props) {
    var _this;
    _classCallCheck(this, SiteOriginPanelsLayoutBlock);
    _this = _super.call(this, props);
    var hasPanelsData = _typeof(props.panelsData) === 'object' && Object.keys(props.panelsData).length > 0;
    var isDefaultModeEdit = window.soPanelsBlockEditorAdmin.defaultMode === 'edit';
    var editMode = hasPanelsData === true ? isDefaultModeEdit : true;
    _this.state = {
      editing: editMode,
      loadingPreview: !editMode,
      previewHtml: '',
      previewInitialized: !editMode,
      pendingPreviewRequest: false,
      panelsInitialized: false
    };
    _this.panelsContainer = wp.element.createRef();
    _this.previewContainer = wp.element.createRef();
    _this.fetchPreviewTimer;
    return _this;
  }
  _createClass(SiteOriginPanelsLayoutBlock, [{
    key: "componentDidMount",
    value: function componentDidMount() {
      this.isStillMounted = true;
      if (!this.state.panelsInitialized) {
        this.setupPanels();
      }
      if (!this.previewInitialized) {
        clearTimeout(this.fetchPreviewTimer);
        var current = this;
        this.fetchPreviewTimer = setTimeout(function () {
          current.fetchPreview(current.props);
        }, 1000);
      }
    }
  }, {
    key: "componentWillUnmount",
    value: function componentWillUnmount() {
      this.isStillMounted = false;
      if (this.builderView) {
        this.builderView.off('content_change');
      }
    }
  }, {
    key: "componentDidUpdate",
    value: function componentDidUpdate(prevProps) {
      if (!this.state.panelsInitialized) {
        this.setupPanels();
      } else if (this.state.loadingPreview) {
        if (!this.state.pendingPreviewRequest) {
          this.setState({
            pendingPreviewRequest: true
          });
          clearTimeout(this.fetchPreviewTimer);
          var current = this;
          this.fetchPreviewTimer = setTimeout(function () {
            current.fetchPreview(current.props);
          }, 1000);
        }
      } else if (!this.state.previewInitialized) {
        jQuery(document).trigger('panels_setup_preview');
        this.setState({
          previewInitialized: true
        });
      }
    }
  }, {
    key: "setupPanels",
    value: function setupPanels() {
      var _this2 = this;
      // Should we set up panels?
      if (!this.state.editing || this.state.panelsInitialized) {
        return;
      }
      var $panelsContainer = jQuery(this.panelsContainer.current);
      var config = {
        editorType: 'standalone',
        loadLiveEditor: false,
        postId: window.soPanelsBlockEditorAdmin.postId,
        editorPreview: window.soPanelsBlockEditorAdmin.liveEditor
      };
      var builderModel = new panels.model.builder();
      this.builderView = new panels.view.builder({
        model: builderModel,
        config: config
      });

      // Make sure panelsData is defined and clone so that we don't alter the underlying attribute.
      var panelsData = JSON.parse(JSON.stringify(jQuery.extend({}, this.props.panelsData)));

      // Disable block selection while dragging rows or widgets.
      var rowOrWidgetMouseDown = function rowOrWidgetMouseDown() {
        if (typeof _this2.props.onRowOrWidgetMouseDown === 'function') {
          _this2.props.onRowOrWidgetMouseDown();
        }
        var rowOrWidgetMouseUp = function rowOrWidgetMouseUp() {
          jQuery(document).off('mouseup', rowOrWidgetMouseUp);
          if (typeof _this2.props.onRowOrWidgetMouseUp === 'function') {
            _this2.props.onRowOrWidgetMouseUp();
          }
        };
        jQuery(document).on('mouseup', rowOrWidgetMouseUp);
      };
      this.builderView.on('row_added', function () {
        _this2.builderView.$('.so-row-move').off('mousedown', rowOrWidgetMouseDown);
        _this2.builderView.$('.so-row-move').on('mousedown', rowOrWidgetMouseDown);
        _this2.builderView.$('.so-widget').off('mousedown', rowOrWidgetMouseDown);
        _this2.builderView.$('.so-widget').on('mousedown', rowOrWidgetMouseDown);
      });
      this.builderView.on('widget_added', function () {
        _this2.builderView.$('.so-widget').off('mousedown', rowOrWidgetMouseDown);
        _this2.builderView.$('.so-widget').on('mousedown', rowOrWidgetMouseDown);
      });
      this.builderView.render().attach({
        container: $panelsContainer
      }).setData(panelsData);
      this.builderView.trigger('builder_resize');

      /**
       * Checks if two panels data objects are equal.
       * @param {Object} newPanelsData - The new panels data object.
       * @param {Object} oldPanelsData - The old panels data object.
       * @returns {boolean} - Returns true if the two panels data objects are equal, otherwise false.
       */
      var SiteOriginIsPanelsEqual = function SiteOriginIsPanelsEqual(newPanelsData, oldPanelsData) {
        if (newPanelsData === oldPanelsData) {
          return true;
        }
        if (!newPanelsData || !oldPanelsData || _typeof(newPanelsData) !== 'object' && _typeof(oldPanelsData) !== 'object') {
          return newPanelsData === oldPanelsData;
        }
        var keys = Object.keys(newPanelsData);
        if (keys.length !== Object.keys(oldPanelsData).length) {
          return false;
        }
        return keys.every(function (k) {
          return SiteOriginIsPanelsEqual(newPanelsData[k], oldPanelsData[k]);
        });
      };
      this.builderView.on('content_change', function () {
        var newPanelsData = _this2.builderView.getData();
        _this2.panelsDataChanged = !SiteOriginIsPanelsEqual(panelsData, newPanelsData);
        if (_this2.panelsDataChanged) {
          if (_this2.props.onContentChange && typeof _this2.props.onContentChange === 'function') {
            _this2.props.onContentChange(newPanelsData);
          }
          _this2.setState({
            loadingPreview: true,
            previewHtml: ''
          });
        }
      });
      jQuery(document).trigger('panels_setup', this.builderView);
      if (typeof window.soPanelsBuilderView == 'undefined') {
        window.soPanelsBuilderView = [];
      }
      window.soPanelsBuilderView.push(this.builderView);
      this.setState({
        panelsInitialized: true
      });
    }
  }, {
    key: "fetchPreview",
    value: function fetchPreview(props) {
      var _this3 = this;
      if (!this.isStillMounted) {
        return;
      }

      // If we don't have panelsData yet, fetch it from PB directly.
      var panelsData = props.panelsData === null ? this.builderView.getData() : props.panelsData;
      this.setState({
        previewInitialized: false
      });
      var fetchRequest = this.currentFetchRequest = jQuery.post({
        url: window.soPanelsBlockEditorAdmin.previewUrl,
        data: {
          action: 'so_panels_layout_block_preview',
          panelsData: JSON.stringify(panelsData)
        }
      }).then(function (preview) {
        if (!_this3.isStillMounted) {
          return;
        }
        setTimeout(function () {
          jQuery(document).trigger('panels_setup_preview');
        }, 1000);
        if (fetchRequest === _this3.currentFetchRequest && preview) {
          _this3.setState({
            previewHtml: preview,
            loadingPreview: false,
            previewInitialized: false,
            pendingPreviewRequest: false
          });
        }
      });
      return fetchRequest;
    }
  }, {
    key: "render",
    value: function render() {
      var _this4 = this;
      var panelsData = this.props.panelsData;
      var switchToEditing = function switchToEditing() {
        _this4.setState({
          editing: true
        });
      };
      var switchToPreview = function switchToPreview() {
        if (panelsData) {
          _this4.setState({
            editing: false
          });
        }
      };
      return /*#__PURE__*/React.createElement(wp.element.Fragment, null, /*#__PURE__*/React.createElement(wp.blockEditor.BlockControls, null, /*#__PURE__*/React.createElement(wp.components.Toolbar, {
        label: wp.i18n.__('Page Builder Mode.', 'siteorigin-panels')
      }, this.state.editing ? /*#__PURE__*/React.createElement(wp.components.ToolbarButton, {
        icon: "visibility",
        className: "components-icon-button components-toolbar__control",
        label: wp.i18n.__('Preview layout.', 'siteorigin-panels'),
        onClick: switchToPreview
      }) : /*#__PURE__*/React.createElement(wp.components.ToolbarButton, {
        icon: "edit",
        className: "components-icon-button components-toolbar__control",
        label: wp.i18n.__('Edit layout.', 'siteorigin-panels'),
        onClick: switchToEditing
      }))), /*#__PURE__*/React.createElement("div", {
        key: "layout-block",
        className: "siteorigin-panels-layout-block-container",
        ref: this.panelsContainer,
        hidden: !this.state.editing
      }), /*#__PURE__*/React.createElement("div", {
        key: "preview",
        className: "so-panels-block-layout-preview-container",
        hidden: this.state.editing
      }, this.state.loadingPreview ? /*#__PURE__*/React.createElement("div", {
        className: "so-panels-spinner-container"
      }, /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement(wp.components.Spinner, null))) : /*#__PURE__*/React.createElement("div", {
        className: "so-panels-raw-html-container",
        ref: this.previewContainer
      }, /*#__PURE__*/React.createElement(wp.element.RawHTML, null, this.state.previewHtml))));
    }
  }]);
  return SiteOriginPanelsLayoutBlock;
}(wp.element.Component);
var hasLayoutCategory = wp.blocks.getCategories().some(function (category) {
  return category.slug === 'layout';
});
wp.blocks.registerBlockType('siteorigin-panels/layout-block', {
  title: wp.i18n.__('SiteOrigin Layout', 'siteorigin-panels'),
  description: wp.i18n.__("Build a layout using SiteOrigin's Page Builder.", 'siteorigin-panels'),
  icon: function icon() {
    return /*#__PURE__*/React.createElement("span", {
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
  edit: function edit(_ref) {
    var attributes = _ref.attributes,
      setAttributes = _ref.setAttributes,
      toggleSelection = _ref.toggleSelection;
    var onLayoutBlockContentChange = function onLayoutBlockContentChange(newPanelsData) {
      if (_typeof(newPanelsData.widgets) === 'object' && Object.keys(newPanelsData.widgets).length > 0) {
        // Send panelsData to server for sanitization.
        var isNewWPBlockEditor = jQuery('.widgets-php').length;
        if (!isNewWPBlockEditor) {
          wp.data.dispatch('core/editor').lockPostSaving();
        }
        jQuery.post(panelsOptions.ajaxurl, {
          action: 'so_panels_builder_content_json',
          panels_data: JSON.stringify(newPanelsData),
          post_id: !isNewWPBlockEditor ? wp.data.select("core/editor").getCurrentPostId() : ''
        }, function (content) {
          var panelsAttributes = {};
          if (content.sanitized_panels_data !== '') {
            panelsAttributes.panelsData = content.sanitized_panels_data;
          }
          if (content.preview !== '') {
            panelsAttributes.contentPreview = content.preview;
          }
          setAttributes(panelsAttributes);
          if (!isNewWPBlockEditor) {
            wp.data.dispatch('core/editor').unlockPostSaving();
          }
        });
      } else {
        setAttributes({
          panelsData: null,
          contentPreview: null
        });
      }
    };
    var disableSelection = function disableSelection() {
      toggleSelection(false);
    };
    var enableSelection = function enableSelection() {
      toggleSelection(true);
    };
    return /*#__PURE__*/React.createElement(SiteOriginPanelsLayoutBlock, {
      panelsData: attributes.panelsData,
      onContentChange: onLayoutBlockContentChange,
      onRowOrWidgetMouseDown: disableSelection,
      onRowOrWidgetMouseUp: enableSelection
    });
  }
});
(function (jQuery) {
  // Resolve Block Editor warning for SO Layout Block.
  var unsubscribe = null;
  unsubscribe = wp.data.subscribe(function () {
    var isEditorReady = false;
    if (wp.data.select('core/block-editor')) {
      isEditorReady = wp.data.select('core/block-editor').hasInserterItems();
    } else if (wp.data.select('core/editor')) {
      isEditorReady = wp.data.select('core/editor').__unstableIsEditorReady();
    }
    if (isEditorReady && unsubscribe) {
      unsubscribe();
      setTimeout(function () {
        jQuery('.wp-block[data-type="siteorigin-panels/layout-block"].has-warning .block-editor-warning__action .components-button').trigger('click');
      }, 250);
    }
  });
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
})(jQuery);

// Detect preview mode changes, and trigger resize.
jQuery(document).on('click', '.block-editor-post-preview__button-resize', function (e) {
  if (!jQuery(this).hasClass('has-icon')) {
    jQuery(window).trigger('resize');
  }
});
"use strict";

function _typeof(obj) { if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

function _classCallCheck(instance, Constructor) { if (!(instance instanceof Constructor)) { throw new TypeError("Cannot call a class as a function"); } }

function _defineProperties(target, props) { for (var i = 0; i < props.length; i++) { var descriptor = props[i]; descriptor.enumerable = descriptor.enumerable || false; descriptor.configurable = true; if ("value" in descriptor) descriptor.writable = true; Object.defineProperty(target, descriptor.key, descriptor); } }

function _createClass(Constructor, protoProps, staticProps) { if (protoProps) _defineProperties(Constructor.prototype, protoProps); if (staticProps) _defineProperties(Constructor, staticProps); return Constructor; }

function _possibleConstructorReturn(self, call) { if (call && (_typeof(call) === "object" || typeof call === "function")) { return call; } return _assertThisInitialized(self); }

function _assertThisInitialized(self) { if (self === void 0) { throw new ReferenceError("this hasn't been initialised - super() hasn't been called"); } return self; }

function _getPrototypeOf(o) { _getPrototypeOf = Object.setPrototypeOf ? Object.getPrototypeOf : function _getPrototypeOf(o) { return o.__proto__ || Object.getPrototypeOf(o); }; return _getPrototypeOf(o); }

function _inherits(subClass, superClass) { if (typeof superClass !== "function" && superClass !== null) { throw new TypeError("Super expression must either be null or a function"); } subClass.prototype = Object.create(superClass && superClass.prototype, { constructor: { value: subClass, writable: true, configurable: true } }); if (superClass) _setPrototypeOf(subClass, superClass); }

function _setPrototypeOf(o, p) { _setPrototypeOf = Object.setPrototypeOf || function _setPrototypeOf(o, p) { o.__proto__ = p; return o; }; return _setPrototypeOf(o, p); }

var _lodash = lodash,
    isEqual = _lodash.isEqual,
    debounce = _lodash.debounce;
var registerBlockType = wp.blocks.registerBlockType;
var _wp$element = wp.element,
    Component = _wp$element.Component,
    Fragment = _wp$element.Fragment,
    RawHTML = _wp$element.RawHTML,
    createRef = _wp$element.createRef;
var BlockControls = wp.editor.BlockControls;
var _wp$components = wp.components,
    Toolbar = _wp$components.Toolbar,
    IconButton = _wp$components.IconButton,
    Spinner = _wp$components.Spinner;
var __ = wp.i18n.__;

var SiteOriginPanelsLayoutBlock =
/*#__PURE__*/
function (_Component) {
  _inherits(SiteOriginPanelsLayoutBlock, _Component);

  function SiteOriginPanelsLayoutBlock(props) {
    var _this;

    _classCallCheck(this, SiteOriginPanelsLayoutBlock);

    _this = _possibleConstructorReturn(this, _getPrototypeOf(SiteOriginPanelsLayoutBlock).call(this, props));
    var editMode = soPanelsBlockEditorAdmin.defaultMode === 'edit';
    _this.state = {
      editing: editMode,
      loadingPreview: !editMode,
      previewHtml: ''
    };
    _this.panelsContainer = createRef();
    _this.panelsInitialized = false;
    _this.previewInitialized = false;
    return _this;
  }

  _createClass(SiteOriginPanelsLayoutBlock, [{
    key: "componentDidMount",
    value: function componentDidMount() {
      this.isStillMounted = true;

      if (this.state.editing) {
        this.setupPanels();
      } else if (!this.state.editing && !this.previewInitialized) {
        this.fetchPreview(this.props);
        this.fetchPreview = debounce(this.fetchPreview, 500);
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
      // let propsChanged = !isEqual( prevProps.panelsData, this.props.panelsData );
      if (this.state.editing && !this.panelsInitialized) {
        this.setupPanels();
      } else if (this.state.loadingPreview) {
        this.fetchPreview(this.props);
      } else {
        $(document).trigger('panels_setup_preview');
        this.previewInitialized = true;
      }
    }
  }, {
    key: "setupPanels",
    value: function setupPanels() {
      var _this2 = this;

      var $panelsContainer = jQuery(this.panelsContainer.current);
      var config = {
        editorType: 'standalone'
      };
      var builderModel = new panels.model.builder();
      this.builderView = new panels.view.builder({
        model: builderModel,
        config: config
      }); // Make sure panelsData is defined and clone so that we don't alter the underlying attribute.

      var panelsData = JSON.parse(JSON.stringify($.extend({}, this.props.panelsData))); // Disable block selection while dragging rows or widgets.

      var disableSelection = function disableSelection() {
        _this2.props.toggleSelection(false);

        var enableSelection = function enableSelection() {
          _this2.props.toggleSelection(true);

          $(document).off('mouseup', enableSelection);
        };

        $(document).on('mouseup', enableSelection);
      };

      this.builderView.on('row_added', function () {
        _this2.builderView.$('.so-row-move').off('mousedown', disableSelection);

        _this2.builderView.$('.so-row-move').on('mousedown', disableSelection);

        _this2.builderView.$('.so-widget').off('mousedown', disableSelection);

        _this2.builderView.$('.so-widget').on('mousedown', disableSelection);
      });
      this.builderView.on('widget_added', function () {
        _this2.builderView.$('.so-widget').off('mousedown', disableSelection);

        _this2.builderView.$('.so-widget').on('mousedown', disableSelection);
      });
      this.builderView.render().attach({
        container: $panelsContainer
      }).setData(panelsData);
      this.builderView.trigger('builder_resize');
      this.builderView.on('content_change', function () {
        var newPanelsData = _this2.builderView.getData();

        _this2.panelsDataChanged = !isEqual(panelsData, newPanelsData);

        if (_this2.panelsDataChanged) {
          _this2.props.onContentChange(newPanelsData);

          _this2.setState({
            loadingPreview: true,
            previewHtml: ''
          });
        }
      });
      $(document).trigger('panels_setup', this.builderView);
      this.panelsInitialized = true;
    }
  }, {
    key: "fetchPreview",
    value: function fetchPreview(props) {
      var _this3 = this;

      if (!this.isStillMounted) {
        return;
      }

      this.previewInitialized = false; // var loadingPreview = !props.editing && !props.previewHtml && props.attributes.panelsData;

      var fetchRequest = this.currentFetchRequest = $.post({
        url: soPanelsBlockEditorAdmin.previewUrl,
        data: {
          action: 'so_panels_block_editor_preview',
          panelsData: JSON.stringify(props.panelsData)
        }
      }).then(function (preview) {
        if (_this3.isStillMounted && fetchRequest === _this3.currentFetchRequest && preview) {
          _this3.setState({
            previewHtml: preview,
            loadingPreview: false
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
        _this4.panelsInitialized = false;

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

      if (this.state.editing || !panelsData) {
        return React.createElement(Fragment, null, React.createElement(BlockControls, null, React.createElement(Toolbar, null, React.createElement(IconButton, {
          icon: "visibility",
          className: "components-icon-button components-toolbar__control",
          label: __('Preview layout.', 'siteorigin-panels'),
          onClick: switchToPreview
        }))), React.createElement("div", {
          key: "layout-block",
          className: "siteorigin-panels-layout-block-container",
          ref: this.panelsContainer
        }));
      } else {
        var loadingPreview = this.state.loadingPreview;
        return React.createElement(Fragment, null, React.createElement(BlockControls, null, React.createElement(Toolbar, null, React.createElement(IconButton, {
          icon: "edit",
          className: "components-icon-button components-toolbar__control",
          label: __('Edit layout.', 'siteorigin-panels'),
          onClick: switchToEditing
        }))), React.createElement("div", {
          key: "preview",
          className: "so-panels-block-layout-preview-container"
        }, loadingPreview ? React.createElement("div", {
          className: "so-panels-spinner-container"
        }, React.createElement("span", null, React.createElement(Spinner, null))) : React.createElement(RawHTML, null, this.state.previewHtml)));
      }
    }
  }]);

  return SiteOriginPanelsLayoutBlock;
}(Component);

registerBlockType('siteorigin-panels/layout-block', {
  title: __('SiteOrigin Layout', 'siteorigin-panels'),
  description: __("Build a layout using SiteOrigin's Page Builder.", 'siteorigin-panels'),
  icon: function icon() {
    return React.createElement("span", {
      className: "siteorigin-panels-block-icon"
    });
  },
  category: 'layout',
  keywords: ['page builder', 'column,grid', 'panel'],
  supports: {
    html: false
  },
  attributes: {
    panelsData: {
      type: 'object'
    }
  },
  edit: function edit(_ref) {
    var attributes = _ref.attributes,
        className = _ref.className,
        setAttributes = _ref.setAttributes,
        toggleSelection = _ref.toggleSelection;

    var onLayoutBlockContentChange = function onLayoutBlockContentChange(newContent) {
      setAttributes({
        panelsData: newContent
      });
    };

    return React.createElement(SiteOriginPanelsLayoutBlock, {
      panelsData: attributes.panelsData,
      onContentChange: onLayoutBlockContentChange,
      toggleSelection: toggleSelection
    });
  },
  save: function save() {
    // Render in PHP
    return null;
  }
});
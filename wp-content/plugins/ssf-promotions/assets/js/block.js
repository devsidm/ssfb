(function (blocks, element, blockEditor, components, i18n) {
  'use strict';

  var el = element.createElement;
  var InspectorControls = blockEditor.InspectorControls;
  var PanelBody = components.PanelBody;
  var RangeControl = components.RangeControl;
  var SelectControl = components.SelectControl;
  var Placeholder = components.Placeholder;
  var __ = i18n.__;

  blocks.registerBlockType('ssf/current-promotions', {
    apiVersion: 2,
    title: __('SSF – Aktuellt', 'ssf-promotions'),
    icon: 'megaphone',
    category: 'widgets',
    attributes: {
      max: { type: 'number', default: 3 },
      type: { type: 'string', default: '' },
      layout: { type: 'string', default: 'auto' },
      location: { type: 'string', default: 'home' }
    },
    edit: function (props) {
      return el('div', {},
        el(InspectorControls, {},
          el(PanelBody, { title: __('Visning', 'ssf-promotions'), initialOpen: true },
            el(RangeControl, { label: __('Max antal', 'ssf-promotions'), min: 1, max: 10, value: props.attributes.max, onChange: function (value) { props.setAttributes({ max: value }); } }),
            el(SelectControl, { label: __('Layout', 'ssf-promotions'), value: props.attributes.layout, options: [{ label: __('Automatisk', 'ssf-promotions'), value: 'auto' }, { label: __('Banner', 'ssf-promotions'), value: 'banner' }, { label: __('Kort', 'ssf-promotions'), value: 'card' }], onChange: function (value) { props.setAttributes({ layout: value }); } }),
            el(SelectControl, { label: __('Typfilter', 'ssf-promotions'), value: props.attributes.type, options: [{ label: __('Alla typer', 'ssf-promotions'), value: '' }, { label: __('Årsmöte', 'ssf-promotions'), value: 'annual_meeting' }, { label: __('Motioner', 'ssf-promotions'), value: 'motions' }, { label: __('Nyhetsbrev', 'ssf-promotions'), value: 'newsletter' }, { label: __('Evenemang', 'ssf-promotions'), value: 'event' }, { label: __('Information', 'ssf-promotions'), value: 'information' }], onChange: function (value) { props.setAttributes({ type: value }); } })
          )
        ),
        el(Placeholder, { icon: 'megaphone', label: __('SSF – Aktuellt', 'ssf-promotions') }, __('Aktiva budskap visas här på webbplatsen.', 'ssf-promotions'))
      );
    },
    save: function () {
      return null;
    }
  });
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n));

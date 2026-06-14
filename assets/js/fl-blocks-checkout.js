/* global wc, wp */
( function () {
	'use strict';

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var decodeEntities        = wp.htmlEntities.decodeEntities;
	var createElement         = wp.element.createElement;
	var useState              = wp.element.useState;
	var useEffect             = wp.element.useEffect;
	var getSetting            = wc.wcSettings.getSetting;

	var settings = getSetting( 'forgelayer_data', {} );
	var options  = settings.options  || [];
	var i18n     = settings.i18n    || {};

	/**
	 * Payment method content — shown inside the blocks checkout payment step.
	 */
	function ForgeLayerPaymentMethod( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse      = props.emitResponse;

		// Default to first option
		var defaultValue = options.length > 0 ? options[0].value : '';
		var state        = useState( defaultValue );
		var selected     = state[0];
		var setSelected  = state[1];

		// When the form is submitted, pass the selected option in payment data
		useEffect( function () {
			var unsubscribe = eventRegistration.onPaymentSetup( function () {
				if ( ! selected ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: i18n.selectNetwork || 'Please select a payment network.',
					};
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							fl_payment_option: selected,
						},
					},
				};
			} );
			return unsubscribe;
		}, [ selected, eventRegistration.onPaymentSetup ] );

		if ( options.length === 0 ) {
			return createElement(
				'p',
				{ className: 'fl-error' },
				i18n.noOptions || 'No payment options available.'
			);
		}

		var description = settings.description
			? createElement( 'p', { className: 'fl-blocks-description' }, decodeEntities( settings.description ) )
			: null;

		var label = createElement(
			'p',
			{ className: 'fl-select-label' },
			createElement( 'strong', null, decodeEntities( i18n.selectNetwork || 'Select network & currency:' ) )
		);

		var optionEls = options.map( function ( opt ) {
			return createElement(
				'label',
				{
					key:       opt.value,
					className: 'fl-option' + ( selected === opt.value ? ' fl-option-selected' : '' ),
				},
				createElement( 'input', {
					type:     'radio',
					name:     'fl_payment_option',
					value:    opt.value,
					checked:  selected === opt.value,
					onChange: function () { setSelected( opt.value ); },
				} ),
				createElement( 'span', { className: 'fl-option-label' }, decodeEntities( opt.label ) )
			);
		} );

		var grid = createElement( 'div', { className: 'fl-options-grid' }, optionEls );

		return createElement(
			'div',
			{ className: 'fl-payment-options' },
			description,
			label,
			grid
		);
	}

	/**
	 * Label shown next to the payment method radio button in the checkout list.
	 */
	function ForgeLayerLabel( props ) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;
		return createElement( PaymentMethodLabel, { text: decodeEntities( settings.title || 'Pay with Cryptocurrency' ) } );
	}

	registerPaymentMethod( {
		name:           'forgelayer',
		label:          createElement( ForgeLayerLabel, null ),
		content:        createElement( ForgeLayerPaymentMethod, null ),
		edit:           createElement( ForgeLayerPaymentMethod, null ),
		canMakePayment: function () { return options.length > 0; },
		ariaLabel:      decodeEntities( settings.title || 'Pay with Cryptocurrency' ),
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();

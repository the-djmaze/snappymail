import { i18n } from 'Common/Translator';
import { defaultOptionsAfterRender } from 'Common/Utils';
import { AbstractInput } from 'Component/AbstractInput';

export class SelectComponent extends AbstractInput {
	/**
	 * @param {Object} params
	 */
	constructor(params) {
		super(params);

		this.options = params.options || '';

		this.optionsText = params.optionsText || null;
		this.optionsValue = params.optionsValue || null;
		this.optionsCaption = params.optionsCaption || null;

		if (this.optionsCaption) {
			this.optionsCaption = i18n(this.optionsCaption);
		}

		this.defaultOptionsAfterRender = defaultOptionsAfterRender;
	}
}

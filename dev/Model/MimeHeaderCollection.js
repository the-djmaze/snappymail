import { AbstractCollectionModel } from 'Model/AbstractCollection';
import { MimeHeaderModel } from 'Model/MimeHeader';

'use strict';

export class MimeHeaderCollectionModel extends AbstractCollectionModel
{
	/**
	 * @param {?Array} json
	 * @returns {MimeHeaderCollectionModel}
	 */
	static reviveFromJson(items) {
		return super.reviveFromJson(items, header => MimeHeaderModel.reviveFromJson(header));
	}

	/**
	 * @param {string} name
	 * @returns {?MimeHeader}
	 */
	getByName(name)
	{
		name = name.toLowerCase();
		return this.find(header => header.name.toLowerCase() === name);
	}

	valueByName(name)
	{
		const header = this.getByName(name);
		return header ? header.value : '';
	}

	valuesByName(name)
	{
		name = name.toLowerCase();
		return this.filter(header => header.name.toLowerCase() === name).map(header => header.value);
	}

	/**
	 * Detects a message that was submitted and delivered entirely on this mail server
	 * (e.g. user@domain.tld -> otheruser@domain.tld via Postfix+Dovecot LMTP), so it
	 * never passed through the public internet and SPF/DKIM/DMARC results are not
	 * meaningful (they'll typically be missing or fail).
	 *
	 * Only the topmost Received header(s) are trusted: those are prepended by our own
	 * MTA on final delivery, so a remote sender can't forge them by stuffing fake
	 * "Received: ... with LMTP" lines into a message before we receive it -- those
	 * would end up lower in the chain, behind the real hop our own server added.
	 *
	 * Recognizes the standard Postfix Received header wording:
	 * - final hop:      "... by host (Postfix) with LMTP ..."
	 * - submission hop: "... by host (Postfix) with ESMTPSA/ESMTPA ..." (SASL authenticated)
	 *
	 * Like:
	 * 	Received: from example.com
	 * 		by example.com with LMTP
	 * 		id MTP9IzMc82qLQjQAchIGgQ
	 * 		(envelope-from <demo@snappymail.eu>)
	 * 		for <demo@snappymail.eu>; Tue, 10 Nov 2026 14:29:23 +0100
	 * 	Received: from snappymail.eu (localhost [IPv6:::1])
	 * 		by example.com (Postfix) with ESMTP id 3DFEE60795
	 * 		for <demo@snappymail.eu>; Tue, 10 Nov 2026 14:29:23 +0100 (CET)
	 *  Received: by example.com (Postfix
	 */
	isLocalDelivery()
	{
		const aReceived = this.valuesByName('Received');
		if (!aReceived.length) {
			// When not send there are none, like a saved draft
			return true;
		}

		if (!/^from\s+([^\s]+).+?by\s+([^\s]+).+?with\s+LMTP\s/si.test(aReceived[0])) {
			return false;
		}

		if (!aReceived[1]) {
			return false;
		}
		return /\swith\s+ESMTP(?:SA|A)?\s/i.test(aReceived[1])
		    || /^by\s+([^\s]+)\s+\(Postfix?/i.test(aReceived[1]);
	}
}

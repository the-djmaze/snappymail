<?php

namespace RainLoop\Enumerations;

enum Capa: string
{
	case ADDITIONAL_ACCOUNTS = 'AdditionalAccounts';
	case ATTACHMENTS_ACTIONS = 'AttachmentsActions';
	case ATTACHMENT_THUMBNAILS = 'AttachmentThumbnails';
	case CONTACTS = 'Contacts';
	case DANGEROUS_ACTIONS = 'DangerousActions';
	case GNUPG = 'GnuPG';
	case IDENTITIES = 'Identities';
	case OPENPGP = 'OpenPGP';
	case SIEVE = 'Sieve';
	case THEMES = 'Themes';
	case USER_BACKGROUND = 'UserBackground';
}

# SnappyMail plugin for nextcloud

## Nextcloud Addressbook to save recipients' contacts

 This plugin can let user to choose which nextcloud addressbook to use save recipients (requires enableing snappyMail contacts) . This is opt-in feature (enabled by admin). After admin enable this, user will find a dropdown in his/her SnappyMail's `Contacts` section, containing all his/her addressbook. It works better with [Nextcloud Contacts App](https://github.com/nextcloud/contacts/)

### Admin settings

- `enableNcAddressbook` : Enable: save recipient contacts into nextcloud addressbook feature. User will see a new section into her/his `Contacts` setting, so s/he can choose Nextcloud addressbook to save recipients' contacts. Default value: `false`
- `disableSnappymailContactsUI` : Hide SnappyMail internal addressbook accesspoint. This is recomended if nextcloud addressbook to save recipients contacts is being used. So, users won't be confused to having multiple addressbook options. Default value: `false`
- `defaultNCAddressbookUri` : Default nextcloud addressbook URI to save recipients' contacts. Ignored if `enableNcAddressbook` is set to false. User can change this value for her/his account. Default value: `webmail`
- `defaultNCAddressbookName` : Default nextcloud addressbook Name to save recipients' contacts. Ignored if `enableNcAddressbook` is set to false. User can change this value for her/his account. Default value: `WebMail`
- `defaultNCAddressbookDescription` : Default nextcloud addressbook description to save recipients' contacts. Ignored if `enableNcAddressbook` is set to false. User can change this value for her/his account. Default value: `Recipients from snappymail`


(rl => {
//	if (rl.settings.get('Nextcloud'))
	const templateId = 'MailMessageView';

	addEventListener('rl-view-model.create', e => {
		if (templateId === e.detail.viewModelTemplateID) {

			const
				template = document.getElementById(templateId),
				cfg = rl.settings.get('Nextcloud'),
				attachmentsControls = template.content.querySelector('.attachmentsControls'),
				msgMenu = template.content.querySelector('#more-view-dropdown-id + menu');

			if (attachmentsControls) {
				attachmentsControls.append(Element.fromHTML(`<span>
					<i class="fontastic iconcolor-red" data-bind="visible: saveNextcloudError">✖</i>
					<i class="fontastic" data-bind="visible: !saveNextcloudError(),
						css: {'icon-spinner': saveNextcloudLoading()}">💾</i>
					<span class="g-ui-link" data-bind="click: saveNextcloud" data-i18n="NEXTCLOUD/SAVE_ATTACHMENTS"></span>
				</span>`));

				// https://github.com/nextcloud/calendar/issues/4684
				if (cfg.CalDAV) {
					attachmentsControls.append(Element.fromHTML(`<span data-bind="visible: nextcloudICSShow" data-icon="📅">
						<span class="g-ui-link" data-bind="click: nextcloudSaveICS" data-i18n="NEXTCLOUD/SAVE_ICS"></span>
					</span>`));
					attachmentsControls.append(Element.fromHTML(`<span data-bind="visible: nextcloudICSOldInvitation" data-icon="📅">
						<span data-i18n="NEXTCLOUD/OLD_INVITATION"></span>
					</span>`))
					attachmentsControls.append(Element.fromHTML(`<span data-bind="visible: nextcloudICSNewInvitation" data-icon="📅">
						<span class="g-ui-link" data-bind="click: nextcloudSaveICS" data-i18n="NEXTCLOUD/UPDATE_ON_MY_CALENDAR"></span>
					</span>`))
					attachmentsControls.append(Element.fromHTML(`<span data-bind="visible: nextcloudICSLastInvitation" data-icon="📅">
						<span data-i18n="NEXTCLOUD/LAST_INVITATION"></span>
					</span>`))
				}
			}
/*
			// https://github.com/the-djmaze/snappymail/issues/592
			if (cfg.CalDAV) {
				const attachmentsPlace = template.content.querySelector('.attachmentsPlace');
				attachmentsPlace.after(Element.fromHTML(`
				<table data-bind="if: nextcloudICS, visible: nextcloudICS"><tbody style="white-space:pre">
					<tr><td>Summary</td><td data-icon="📅" data-bind="text: nextcloudICS().SUMMARY"></td></tr>
					<tr><td>Organizer</td><td data-bind="text: nextcloudICS().ORGANIZER"></td></tr>
					<tr><td>Start</td><td data-bind="text: nextcloudICS().DTSTART"></td></tr>
					<tr><td>End</td><td data-bind="text: nextcloudICS().DTEND"></td></tr>
					<tr><td>Transparency</td><td data-bind="text: nextcloudICS().TRANSP"></td></tr>
					<tr data-bind="foreach: nextcloudICS().ATTENDEE">
						<td></td><td data-bind="text: $data.replace(/;/g,';\\n')"></td>
					</tr>
				</tbody></table>`));
			}
*/
			if (msgMenu) {
				msgMenu.append(Element.fromHTML(`<li role="presentation">
					<a href="#" tabindex="-1" data-icon="📥" data-bind="click: nextcloudSaveMsg" data-i18n="NEXTCLOUD/SAVE_EML"></a>
				</li>`));
			}

			let view = e.detail;
			view.saveNextcloudError = ko.observable(false).extend({ falseTimeout: 7000 });
			view.saveNextcloudLoading = ko.observable(false);
			view.saveNextcloud = () => {
				const
					hashes = (view.message()?.attachments || [])
					.map(item => item?.checked() /*&& !item?.isLinked()*/ ? item.download : '')
					.filter(v => v);
				if (hashes.length) {
					view.saveNextcloudLoading(true);
					rl.nextcloud.selectFolder().then(folder => {
						if (folder) {
							rl.fetchJSON('./?/Json/&q[]=/0/', {}, {
								Action: 'AttachmentsActions',
								target: 'nextcloud',
								hashes: hashes,
								NcFolder: folder
							})
							.then(result => {
								view.saveNextcloudLoading(false);
								if (result?.Result) {
									// success
								} else {
									view.saveNextcloudError(true);
								}
							})
							.catch(() => {
								view.saveNextcloudLoading(false);
								view.saveNextcloudError(true);
							});
						} else {
							view.saveNextcloudLoading(false);
						}
					});
				}
			};

			view.nextcloudSaveMsg = () => {
				rl.nextcloud.selectFolder().then(folder => {
					let msg = view.message();
					folder && rl.pluginRemoteRequest(
						(iError, data) => {
							console.dir({
								iError:iError,
								data:data
							});
						},
						'NextcloudSaveMsg',
						{
							'msgHash': msg.requestHash,
							'folder': folder,
							'filename': msg.subject()
						}
					);
				});
			};

			view.nextcloudICS = ko.observable(null);
			view.nextcloudICSOldInvitation = ko.observable(null);
			view.nextcloudICSNewInvitation = ko.observable(null);
			view.nextcloudICSLastInvitation = ko.observable(null);

			view.nextcloudICSShow = ko.observable(null);

			view.nextcloudICSCalendar = ko.observable(null);
			view.filteredEventsUrls = ko.observable([]);

			view.nextcloudSaveICS = async () => {
				let VEVENT = view.nextcloudICS();
				VEVENT = await view.handleUpdatedRecurrentEvents(VEVENT)
				VEVENT && rl.nextcloud.selectCalendar(VEVENT)
			}


			view.handleUpdatedRecurrentEvents = async (VEVENT) => {

				const uid = VEVENT.UID

				let makePUTRequest = false

				const filteredEventUrls = view.filteredEventUrls

				if (filteredEventUrls.length > 1) {
					// console.warn('filteredEventUrls.length > 1')
				}
				else if (filteredEventUrls.length == 1) {
					const eventUrl = filteredEventUrls[0]
					const eventText = await fetchEvent(eventUrl)

					// don't do anything for equal cards
					if (VEVENT.rawText == eventText) {
						return VEVENT
					}

					const newVeventsText = extractVEVENTs(VEVENT.rawText)
					const oldVeventsText = extractVEVENTs(eventText)

					// if there's only one event in the old card, it can't be an edit of the exception card
					if (oldVeventsText.length == 1) {
						const newRecurrenceId = extractProperties('RECURRENCE-ID', newVeventsText[0])

						// if there's a property RECURRENCE-ID in the new card, it's an recurrence exception event
						if (newRecurrenceId.length > 0) {
							const updatedVEVENT = {
								'SUMMARY' : VEVENT.SUMMARY,
								'UID' : VEVENT.UID,
								'rawText' : mergeEventTexts(eventText, newVeventsText[0])
							}
							VEVENT = updatedVEVENT
							makePUTRequest = true
						}
						else {
							return VEVENT
						}
					}
					// if there's more than one event in the old card, it's possible to be an inclusion of a new exception card
					// or update of old exception card
					else {
						let recurrenceIdMatch = false

						let isUpdate = false
						const updateData = {
							'oldEventIndex': null,
							'newEventIndex': null,
						}

						// check if it's an update
						for (let i = 0; i < oldVeventsText.length; i++) {
							let oldVeventText = oldVeventsText[i]

							for (let j = 0; j < newVeventsText.length; j++) {
								let newVeventText = newVeventsText[j]

								let oldRecurrenceId = extractProperties('RECURRENCE-ID', oldVeventText)
								let newRecurrenceId = extractProperties('RECURRENCE-ID', newVeventText)
								let oldSequence = extractProperties('SEQUENCE', oldVeventText)
								let newSequence = extractProperties('SEQUENCE', newVeventText)

								if (oldRecurrenceId.length == 0 || newRecurrenceId.length == 0 || oldSequence.length == 0 || newSequence.length == 0) {
									continue
								}

								if (oldRecurrenceId[0] == newRecurrenceId[0]) {
									if (newSequence[0] > oldSequence[0]) {
										isUpdate = true

										updateData.oldEventIndex = i
										updateData.newEventIndex = j

										i = oldVeventsText.length
										j = newVeventsText.length
									}
								}
							}
						}

						// if it's an update...
						if (isUpdate) {
							// substitute old event text for new event text
							const oldEventStart = eventText.indexOf(oldVeventsText[updateData.oldEventIndex])
							const oldEventEnd = oldEventStart + oldVeventsText[updateData.oldEventIndex].length

							const newEvent = eventText.substring(0, oldEventStart) + newVeventsText[updateData.newEventIndex] + eventText.substring(oldEventEnd)

							const updatedVEVENT = {
								'SUMMARY' : VEVENT.SUMMARY,
								'UID': VEVENT.UID,
								'rawText' : newEvent
							}
							VEVENT = updatedVEVENT
							makePUTRequest = true
						}
						// if it's not an update, it's an inclusion, as there's no match of RECURRENCE-ID
						else {
							const updatedVEVENT = {
											'SUMMARY' : VEVENT.SUMMARY,
								'UID' : VEVENT.UID,
								'rawText' : mergeEventTexts(eventText, newVeventsText[0])
							}
							VEVENT = updatedVEVENT
							makePUTRequest = true
						}
					}
				}

				if (makePUTRequest) {
					let href = "/" + (filteredEventUrls[0].split('/').slice(5, -1)[0])

					rl.nextcloud.calendarPut(href, VEVENT, (response) => {
						if (response.status != 201 && response.status != 204) {
							InvitesPopupView.showModal([
								rl.i18n('NEXTCLOUD/EVENT_UPDATE_FAILURE_TITLE'),
								rl.i18n('NEXTCLOUD/EVENT_UPDATE_FAILURE_BODY', {eventName: VEVENT.SUMMARY})
							])
							return
						}
						InvitesPopupView.showModal([
							rl.i18n('NEXTCLOUD/EVENT_UPDATED_TITLE'),
							rl.i18n('NEXTCLOUD/EVENT_UPDATED_BODY', {eventName: VEVENT.SUMMARY})
						])
					})

					return null
				}

				return VEVENT
			}



			/**
			 * TODO
			 */
			view.message.subscribe(msg => {
				view.nextcloudICS(null);
				view.nextcloudICSOldInvitation(null);
				view.nextcloudICSNewInvitation(null);
				view.nextcloudICSLastInvitation(null);
				view.nextcloudICSShow(view.nextcloudICS())


				if (msg && cfg.CalDAV) {
//					let ics = msg.attachments.find(attachment => 'application/ics' == attachment.mimeType);
					let ics = msg.attachments.find(attachment => 'text/calendar' == attachment.mimeType);
					if (ics && ics.download) {
						// fetch it and parse the VEVENT
						rl.fetch(ics.linkDownload())
						.then(response => (response.status < 400) ? response.text() : Promise.reject(new Error({ response })))
						.then(async (text) => {
							let VEVENT,
								VALARM,
								multiple = ['ATTACH','ATTENDEE','CATEGORIES','COMMENT','CONTACT','EXDATE',
									'EXRULE','RSTATUS','RELATED','RESOURCES','RDATE','RRULE'],
								lines = text.split(/\r?\n/),
								i = lines.length;
							while (i--) {
								let line = lines[i];
								if (VEVENT) {
									while (line.startsWith(' ') && i--) {
										line = lines[i] + line.slice(1);
									}
									if (line.startsWith('END:VALARM')) {
										VALARM = {};
										continue;
									} else if (line.startsWith('BEGIN:VALARM')) {
										VEVENT.VALARM || (VEVENT.VALARM = []);
										VEVENT.VALARM.push(VALARM);
										VALARM = null;
										continue;
									} else if (line.startsWith('BEGIN:VEVENT')) {
										break;
									}
									line = line.match(/^([^:;]+)[:;](.+)$/);
									if (line) {
										if (VALARM) {
											VALARM[line[1]] = line[2];
										} else if (multiple.includes(line[1]) || 'X-' == line[1].slice(0,2)) {
											VEVENT[line[1]] || (VEVENT[line[1]] = []);
											VEVENT[line[1]].push(line[2]);
										} else {
											VEVENT[line[1]] = line[2];
										}
									}
								} else if (line.startsWith('END:VEVENT')) {
									VEVENT = {};
								}
							}
//							METHOD:REPLY || METHOD:REQUEST
//							console.dir({VEVENT:VEVENT});
							if (VEVENT) {
								VEVENT.rawText = text;
								VEVENT.isCancelled = () => VEVENT.STATUS?.includes('CANCELLED');
								VEVENT.isConfirmed = () => VEVENT.STATUS?.includes('CONFIRMED');
								VEVENT.shouldReply = () => VEVENT.METHOD?.includes('REPLY');
								console.dir({
									isCancelled: VEVENT.isCancelled(),
									shouldReply: VEVENT.shouldReply()
								});
								view.nextcloudICS(VEVENT);
								view.nextcloudICSShow(true);


								// try to get calendars, save
								const calendarUrls = await fetchCalendarUrls()

								const filteredEventUrls = []
								for (let i = 0; i < calendarUrls.length; i++) {
									let calendarUrl = calendarUrls[i]

									const skipCalendars = ['/inbox/', '/outbox/', '/trashbin/']
									let skip = false
									for (let j = 0; j < skipCalendars.length; j++) {
										if (calendarUrl.includes(skipCalendars[j])) {
											skip = true
											break
										}
									}
									if (skip) {
										continue
									}

									// try to get event
									const eventUrls = await fetchEventUrl(calendarUrl, VEVENT.UID)

									if (eventUrls.length == 0) {
										continue
									}

									eventUrls.forEach((url) => {
										filteredEventUrls.push(url)
									})
								}
								view.filteredEventUrls = filteredEventUrls

								// if there's none, save in view.nextcloudICS
								if (filteredEventUrls.length == 0) {
									view.nextcloudICS(VEVENT);
									view.nextcloudICSShow(true)
								}
								// if there's some...
								else {
									const savedEvent = await fetchEvent(filteredEventUrls[0])

									const newVeventsText = extractVEVENTs(VEVENT.rawText)
									const oldVeventsText = extractVEVENTs(savedEvent)

									// if there's more than one event in the old card, it's possible to be inclusion of new exception card
									// or updated of old exception card
									if (oldVeventsText.length > 1) {
										let recurrenceIdMatch = false

										let oldNewestCreated = null
										let newNewestCreated = null

										// check if it's an update
										for (let i = 0; i < oldVeventsText.length; i++) {
											let oldVeventText = oldVeventsText[i]

											for (let j = 0; j < newVeventsText.length; j++) {
												let newVeventText = newVeventsText[j]

												let oldRecurrenceId = extractProperties('RECURRENCE-ID', oldVeventText)
												let newRecurrenceId = extractProperties('RECURRENCE-ID', newVeventText)
												let oldSequence = extractProperties('SEQUENCE', oldVeventText)
												let newSequence = extractProperties('SEQUENCE', newVeventText)

												if (newRecurrenceId.length == 0 && oldRecurrenceId.length == 1) {
													view.nextcloudICSShow(false)
													view.nextcloudICSOldInvitation(true)
												}

												if (oldRecurrenceId.length > 0 && newRecurrenceId.length > 0 && oldSequence.length > 0 && newSequence.length > 0) {
													if (oldRecurrenceId[0] == newRecurrenceId[0]) {
														if (newSequence[0] < oldSequence[0]) {
															view.nextcloudICSOldInvitation(true)
															view.nextcloudICSShow(false)
														}
														else if (newSequence[0] == oldSequence[0]) {
															view.nextcloudICSLastInvitation(true)
															view.nextcloudICSShow(false)
														}
														else if (newSequence[0] > oldSequence[0]) {
															view.nextcloudICSNewInvitation(true)
															view.nextcloudICSShow(false)
														}

														// exit for loops
														j = newVeventsText.length
														i = oldVeventsText.length
													}
												}

												let oldCreated = extractProperties('CREATED', oldVeventText)
												let newCreated = extractProperties('CREATED', newVeventText)

												if (oldCreated.length == 0 || newCreated.length == 0) {
													continue
												}

												const formattedOldDate = oldCreated[0].replace(/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/, '$1-$2-$3T$4:$5:$6Z');
												const formattedNewDate = newCreated[0].replace(/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/, '$1-$2-$3T$4:$5:$6Z');

												const oldDate = new Date(formattedOldDate)
												const newDate = new Date(formattedNewDate)

												if (oldNewestCreated == null || oldDate > oldNewestCreated) {
													oldNewestCreated = oldDate
												}
												if (newNewestCreated == null || newDate > newNewestCreated) {
													newNewestCreated = newDate
												}
											}
										}

										if (newNewestCreated != null && oldNewestCreated != null) {
											if (newNewestCreated < oldNewestCreated) {
												view.nextcloudICSOldInvitation(true)
												view.nextcloudICSShow(false)
											}
											else if (newNewestCreated == oldNewestCreated) {
												view.nextcloudICSLastInvitation(true)
												view.nextcloudICSShow(false)
											}
										}
									}
									else {
										const oldLastModified = extractProperties('LAST-MODIFIED', oldVeventsText[0])
										const newLastModified = extractProperties('LAST-MODIFIED', newVeventsText[0])

										if (oldLastModified.length == 1 && newLastModified.length == 1) {
											const formattedOldDate = oldLastModified[0].replace(/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/, '$1-$2-$3T$4:$5:$6Z');
											const formattedNewDate = newLastModified[0].replace(/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/, '$1-$2-$3T$4:$5:$6Z');

											const oldDate = new Date(formattedOldDate)
											const newDate = new Date(formattedNewDate)

											if (newDate > oldDate) {
												view.nextcloudICSNewInvitation(true)
												view.nextcloudICSShow(false)
											}
											else if (newDate < oldDate) {
												view.nextcloudICSOldInvitation(true)
												view.nextcloudICSShow(false)
											}
											else {
												view.nextcloudICSLastInvitation(true)
												view.nextcloudICSShow(false)
											}
										}
									}
								}
							}
						});
					}
				}
			});
		}
	});

})(window.rl);


async function fetchCalendarUrls () {
	const username = OC.currentUser
	const requestToken = OC.requestToken

	const url = '/remote.php/dav/calendars/' + username
	const response = await fetch(url, {
		'method': 'PROPFIND',
		'headers': {
			'Depth': '1',
			'Content-Type': 'application/xml',
			'requesttoken' : requestToken
		}
	})

	if (!response.ok) {
		throw new Error('Error fetching calendars', response)
	}

	const responseText = await response.text()

	const parser = new DOMParser()
	const xmlDoc = parser.parseFromString(responseText, 'application/xml')

	const calendarUrls = []

	const hrefElements = xmlDoc.getElementsByTagName('d:href')
	for (let i = 1; i < hrefElements.length; i++) {
		let calendarUrl = hrefElements[i].textContent.trim()
		calendarUrls.push(calendarUrl)
	}

	return calendarUrls
}


async function fetchEventUrl (calendarUrl, uid) {
	const requestToken = OC.requestToken

	const xmlRequestBody = `<?xml version="1.0" encoding="UTF-8" ?>
<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav">
  <C:prop>
    <C:calendar-data/>
  </C:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT">
        <C:prop-filter name="UID">
          <C:text-match collation="i;unicode-casemap" match-type="equals">${uid}</C:text-match>
        </C:prop-filter>
      </C:comp-filter>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>`

	const response = await fetch(calendarUrl, {
		'method': 'REPORT',
		'headers': {
			'Content-Type': 'application/xml',
			'Depth': 1,
			'requesttoken': requestToken
		},
		'body': xmlRequestBody
	})

	const responseText = await response.text()

	const parser = new DOMParser()
	const xmlDoc = parser.parseFromString(responseText, 'application/xml')

	const hrefElements = xmlDoc.getElementsByTagName('d:href')
	const hrefValues = Array.from(hrefElements).map(element => element.textContent)

	return hrefValues
}


async function fetchEvent (eventUrl) {
	const requestToken = OC.requestToken
	const response = await fetch(eventUrl, {
		'method': 'GET',
		'headers': {
			'requesttoken': requestToken
		}
	})
	const responseText = await response.text()

	return responseText
}


function extractVEVENTs(text) {
	let lastEndIndex = 0
	const vEvents = []

	let textToSearch = ""
	let beginIndex = 0
	let endIndex = 0
	let foundVevent = false

	while (true) {
		textToSearch = text.substring(lastEndIndex)

		beginIndex = textToSearch.indexOf('BEGIN:VEVENT')
		if (beginIndex == -1) {
			break
		}
		endIndex = textToSearch.substring(beginIndex).indexOf('END:VEVENT')
		if (endIndex == -1) {
			break
		}
		endIndex += beginIndex

		lastEndIndex = lastEndIndex + endIndex + 'END:VEVENT'.length

		foundVevent = textToSearch.substring(beginIndex + 'BEGIN:VEVENT'.length, endIndex)

		vEvents.push(foundVevent)
	}

	return vEvents
}


function extractProperties (property, text) {
	const matches = text.match(`${property}.*`)

	if (matches == null) {
		return []
	}

	const separatedMatches = matches.map((match) => {
		return match.substring(property.length + 1)
	})

	return separatedMatches
}


function mergeEventTexts (oldEventText, newEventText) {
	const appendIndex = oldEventText.indexOf('END:VEVENT') + 'END:VEVENT'.length
	const updatedEventText = oldEventText.substring(0, appendIndex) + "\nBEGIN:VEVENT" + newEventText + "END:VEVENT" + oldEventText.substring(appendIndex)
	return updatedEventText
}

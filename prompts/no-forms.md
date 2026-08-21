## Forms

NO FORM MARKUP: never emit `<form>`, `<input>`, `<textarea>`, or `<select>` —
this site has no form backend, so a form is dead UI that silently discards
whatever visitors type. Where the brief asks for a contact, booking, or signup
form, present the spec's contact facts instead and make the CTA a mailto: button
minted at the spec's `email_domain` (or a link to the page that holds those
facts).

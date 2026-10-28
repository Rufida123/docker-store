# secrets

Put the Firebase service account key here, named exactly:

    firebase-service-account.json

(Firebase console > Project settings > Service accounts > Generate new private key)

Everything in this folder except this README is ignored by git. Never commit the key:
it gives full access to the Firebase project.

Without the file the API still works; push notifications are simply turned off.

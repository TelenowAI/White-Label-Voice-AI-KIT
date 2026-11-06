# Telenow's API, as the kit builds against it

`openapi.yaml` is Telenow's **released** API document, version **1.1.0**: a byte copy of
`voice_ai_rust/openapi/released/openapi.yaml` at Telenow main `0c31d6a` (sha256 `f918aeec…`; released by `604c8b8`).

Telenow's own tests fail on any change that breaks a client of this version, unless the break is accepted and the
major version goes up. So everything the kit calls is held to this file:
- the resource manifest (`../resources.yaml`) may only name operations and events that are in it;
- every recorded Telenow answer the mock replays (`../telenow-mock/cassettes/`) must validate against it.

To move to a newer release, copy the new released document over this one and run the contract tests.

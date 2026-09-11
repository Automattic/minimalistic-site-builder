## Select and expand one candidate

This request combines concept selection and design expansion.
The Chosen Concept Seed section below contains the full candidate list.
Select one candidate before you expand its direction.
Treat every instruction about the chosen seed as an instruction about that candidate.

Apply these tests in order:

1. Honor all user constraints, including required colors, materials, and exclusions.
2. Prefer a concept that depends on the specific subject. Reject a concept that fits an unrelated subject equally well.
3. Prefer a specific alternative to the category default. Avoid both the obvious default and its obvious opposite.
4. Select a concept that the available type, colors, space, photographs, and bounded decorative device can express.

Candidate position has no priority. Length and adjective count have no value.
Each candidate includes its own font shortlist, hero recipe, blueprint, and palette constraints.
Use those values only from the selected candidate. Keep the other candidates out of the final direction.

Return one JSON object with `winner`, `why`, and `direction`.
Set `winner` to the selected candidate's printed integer index.
Set `why` to one short sentence that explains the choice.
Set `direction` to the full direction object specified below.
The response needs all three keys, even though the direction example below shows only `direction`.

# itsl-merge.jq — Merge ITSL overlay onto upstream package.json or composer.json
#
# Conventions in the overlay file:
#   "key": value        — set/override (deep merge for objects, replace for scalars/arrays)
#   "key+": [...]       — append to existing array (deduplicates)
#   "key-": [...]       — remove entries (array items by value, or object keys by name)
#   "__REMOVE__"        — as a value, removes that key entirely from the parent
#
# Processing order: set/override, then appends, then removes (per nesting level).
#
# Usage: jq -s -f scripts/itsl-merge.jq upstream/file.json itsl-npm-deps.json

def dedup: reduce .[] as $x ([]; if (. | index($x)) then . else . + [$x] end);

def itsl_merge($base; $overlay):
  if ($overlay | type) == "object" then
    # Separate entries by suffix convention
    ($overlay | to_entries | map(select(.key | endswith("+"))) |
      map({key: (.key | rtrimstr("+")), value: .value})) as $appends |
    ($overlay | to_entries | map(select(.key | endswith("-"))) |
      map({key: (.key | rtrimstr("-")), value: .value})) as $removes |
    ($overlay | to_entries |
      map(select(.key | (endswith("+") or endswith("-")) | not))) as $regular |

    # 1. Apply set/override with recursive merge for objects
    (($base // {}) |
      reduce ($regular[]) as $entry (.;
        if $entry.value == "__REMOVE__" then
          del(.[$entry.key])
        elif ($entry.value | type) == "object" and (.[$entry.key] | type) == "object" then
          .[$entry.key] = itsl_merge(.[$entry.key]; $entry.value)
        else
          .[$entry.key] = $entry.value
        end
      )
    ) |

    # 2. Apply appends
    reduce ($appends[]) as $entry (.;
      if (.[$entry.key] | type) == "array" then
        .[$entry.key] = ([.[$entry.key][], $entry.value[]] | dedup)
      elif .[$entry.key] == null then
        # Absent in base: "+" on a missing key just sets it.
        .[$entry.key] = $entry.value
      else
        # "+" on an existing non-array (scalar/object) is a misuse — the
        # suffix is array-append only. Surface it rather than silently
        # replacing the base value.
        error("itsl-merge: '\($entry.key)+' append targets a \(.[$entry.key] | type), but '+' appends to arrays only")
      end
    ) |

    # 3. Apply removes
    reduce ($removes[]) as $entry (.;
      if (.[$entry.key] | type) == "object" then
        reduce ($entry.value[]) as $rkey (.;
          del(.[$entry.key][$rkey])
        )
      elif (.[$entry.key] | type) == "array" then
        .[$entry.key] = [.[$entry.key][] |
          select(. as $v | ($entry.value | index($v)) | not)]
      else .
      end
    )
  elif ($overlay | type) == "array" then
    $overlay
  else
    $overlay
  end;

itsl_merge(.[0]; .[1])

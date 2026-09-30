# Firewall model training data — provenance and licences

Every dataset used to train an embedded model artifact MUST be recorded
here before training. Never commit raw dataset files to the repository —
only the trained artifact and its metadata.

## Embedded artifact

| Field | Value |
|---|---|
| Artifact | `resources/firewall/model/prompt-injection-v1.rbx` |
| Version | 1 |
| Training corpus | Seed corpus shipped in this repository (synthetic multilingual seed rows, see below) |
| Status | **Seed model** — trains cleanly on the seed rows; replace with a model trained on real datasets for production use |

### Seed corpus

The v1 artifact is trained on the repository's own multilingual seed rows
(the same corpus used by the test suite): EN/ES injection phrases
(override_instructions, role_hijack, prompt_leak categories) plus benign
kitchen/list/meal-planning sentences. It demonstrates the pipeline
end-to-end and gives acceptable behaviour out of the box, but it is NOT a
substitute for a model trained on real, diverse datasets.

## Candidate public datasets

Verify schema AND licence before training with each source. Record the
verification result (download date, row counts, class balance) in a new
row below. Download via the built-in command:

```bash
php artisan ai-agents:firewall:download <repo-id> --config=full --split=train
```

Then train with the matching adapter:

```bash
php artisan ai-agents:firewall:train storage/app/private/firewall-datasets/<slug>.jsonl \
    --source=<adapter-name> \
    --out=resources/firewall/model/prompt-injection-v2.rbx
```

| Dataset (Hugging Face ID) | Adapter file | Schema verification | Licence verification | Balance notes |
|---|---|---|---|---|
| `neuralchemy/Prompt-injection-dataset` | `datasets/neuralchemy.php` | **VERIFIED** (config=`full`, columns: `text`, `label` int 0/1, `category`, `source`, `severity`, `group_id`, `augmented`, `tags`; 14,036 train rows) | Apache-2.0 | 3,250 inj / 1,939 ben (in downloaded subset) |
| `deepset/prompt-injections` | `datasets/deepset.php` | **PENDING** | **PENDING** | most-cited binary dataset |
| `jackhhao/jailbreak-classification` | `datasets/jackhhao.php` | **PENDING** | **PENDING** | jailbreak/regular binary |
| `xTRam1/safe-guard-prompt-injection` | `datasets/xtram1.php` | **PENDING** | **PENDING** | balanced |
| `rubend18/ChatGPT-Jailbreak-Prompts` | `datasets/rubend18.php` | **PENDING** | **PENDING** | positive-only (reinforce injection class) |
| `lmsys/toxic-chat` | `datasets/lmsys.php` | **PENDING** | **PENDING** | mostly benign — cut false positives |

## Rules

1. **Verify before training**: confirm column names and label vocabulary
   against the actual downloaded file; fix the adapter file accordingly.
2. **Verify the licence**: only train (and distribute artifacts) with
   datasets whose licence permits it; note the licence identifier here.
3. **Never commit raw data**: the repository carries only the trained
   artifact and this provenance file.
4. **Gate on held-out metrics**: only promote an artifact when
   `ai-agents:firewall:evaluate` reports acceptable precision/recall on a
   split NOT used for training, and the benign-corpus regression test
   (`FalsePositiveRegressionTest`) stays green.
5. **Version the artifact name**: new training runs write a new file
   (v2, v3...) and flip `AI_AGENTS_FIREWALL_CLASSIFIER_PATH`; the old
   artifact stays for rollback.

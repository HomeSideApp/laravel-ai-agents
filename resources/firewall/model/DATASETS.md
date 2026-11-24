# Firewall model training data — provenance and licences

Every dataset used to train an embedded model artifact MUST be recorded
here before training. Never commit raw dataset files to the repository —
only the trained artifact and its metadata.

## Embedded artifact

| Field | Value |
|---|---|
| Artifact | `resources/firewall/model/prompt-injection-v1.rbx` |
| Version | 1 |
| Training corpus | `neuralchemy/Prompt-injection-dataset` (80% split) + domain rows from `corpus.php` repeated 25x as hard negatives |
| Status | Trained on real data; promoted with held-out metrics. Regenerate with `train-dataset.php` |

### Combined corpus (v1, current)

Trained on the public dataset below plus the repository's domain rows. The domain
rows are not decoration: a model trained on the public dataset alone scored
`agregá 2 kg de asado` as an attack and flagged **all 55** held-out app prompts,
because that dataset's benign class is English wiki and jailbreak-game text, so
every colloquial Spanish message is out of distribution. Repeating the domain
rows as hard negatives raised held-out f1 from 0.866 to 0.908 and brought the
worst benign score back from 0.734 to 0.580.

Measured on `eval-cases.php` (held out from training) and the 20% split:

| Artifact | f1 (held-out real) | Worst benign | App benign >= 0.70 | App attacks >= 0.50 |
|---|---|---|---|---|
| seed corpus only | — | 0.423 | 0 | 66.7% |
| public dataset only | 0.866 | 0.734 | 55 / 55 | 100% |
| **combined (v1)** | **0.908** | **0.580** | **0** | **93.9%** |

0.70 matters because the classifier carries weight 0.5 into an aggregate whose
`flag` threshold is 0.35: below 0.70 it cannot raise an alert on its own.

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
| `neuralchemy/Prompt-injection-dataset` | `datasets/neuralchemy.php` | **VERIFIED** (config=`full`, columns: `text`, `label` int 0/1, `category`, `source`, `severity`, `group_id`, `augmented`, `tags`; 14,036 train rows) | Apache-2.0 | downloaded 2026-11-24: 9,500 rows (5,961 inj / 3,539 ben); split 7,600 train / 1,900 held-out with seed 42; 18 rows skipped by the adapter (no usable text) |
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

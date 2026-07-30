import Component from 'ShopUi/models/component';

const SUBMIT_URL = '/search-ranking-optimizer-widget/submit-relevance-judgment';
const CLEAR_URL = '/search-ranking-optimizer-widget/clear-relevance-judgment';

export default class SearchRankingOptimizerProductRating extends Component {
    /**
     * The three heart/check/x buttons, in DOM order.
     */
    buttons: HTMLButtonElement[];

    /**
     * The canonical (un-canonicalized on this side — Zed canonicalizes) search term this SRP's results
     * are for, read once from the wrapper's own data attribute rather than re-parsed from the URL.
     */
    searchTerm: string;

    /**
     * The product abstract id this rating widget instance belongs to.
     */
    idProductAbstract: string;

    /**
     * CSRF token for the submit/clear endpoints — neither is bound to a Symfony Form, so this is generated
     * via `searchRankingOptimizerRatingCsrfToken()` (SearchRankingOptimizerWidgetTwigPlugin) instead and
     * sent as a plain POST field, validated server-side against the same token id.
     */
    csrfToken: string;

    protected readyCallback(): void {
        this.buttons = <HTMLButtonElement[]>Array.from(this.querySelectorAll(`.${this.jsName}__button`));
        this.searchTerm = this.dataset.searchTerm ?? '';
        this.idProductAbstract = this.dataset.idProductAbstract ?? '';
        this.csrfToken = this.dataset.csrfToken ?? '';

        if (this.buttons.length === 0) {
            return;
        }

        this.mapEvents();
    }

    protected mapEvents(): void {
        this.buttons.forEach((button) => button.addEventListener('click', () => this.handleClick(button)));
    }

    /**
     * Single-select: clicking a button that is not yet pressed selects it and deselects the other two
     * (visual state only, before the network round-trip — see the scss for how `aria-pressed` drives the
     * heart/x red-family vs. check green colorization). Clicking the ALREADY-pressed button unselects it
     * (clears the judgment entirely) rather than re-submitting it — the one case where a click means
     * "delete my rating" instead of "set my rating".
     *
     * @param button - the button that was clicked.
     */
    protected handleClick(button: HTMLButtonElement): void {
        const wasPressed = button.getAttribute('aria-pressed') === 'true';

        if (wasPressed) {
            this.setPressed(undefined);
            this.clearJudgment(button);

            return;
        }

        const ratingType = button.dataset.ratingType ?? '';
        const previouslyPressed = this.buttons.find((candidate) => candidate.getAttribute('aria-pressed') === 'true');

        this.setPressed(button);
        this.submitJudgment(ratingType, button, previouslyPressed);
    }

    /**
     * @param pressedButton - the button to mark pressed; every other button in this widget is cleared.
     * Pass `undefined` to clear all three (nothing pressed).
     */
    protected setPressed(pressedButton: HTMLButtonElement | undefined): void {
        this.buttons.forEach((button) => button.setAttribute('aria-pressed', String(button === pressedButton)));
    }

    /**
     * On a failed or unauthorized submission, reverts the visual state to whatever it was before this
     * click — a customer should never see a colorized button for a judgment that was not actually
     * persisted.
     *
     * @param ratingType - one of SearchRankingOptimizerConfig::RATING_TYPE_* on the PHP side.
     * @param clickedButton - the button that was clicked, for revert-on-failure.
     * @param previouslyPressedButton - whichever button was pressed before this click, if any.
     */
    protected submitJudgment(
        ratingType: string,
        clickedButton: HTMLButtonElement,
        previouslyPressedButton: HTMLButtonElement | undefined,
    ): void {
        const body = new URLSearchParams({
            searchTerm: this.searchTerm,
            idProductAbstract: this.idProductAbstract,
            ratingType,
            _csrf_token: this.csrfToken,
        });

        fetch(SUBMIT_URL, {
            method: 'POST',
            body,
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((responseData: { isSuccess: boolean }) => {
                if (!responseData.isSuccess) {
                    this.setPressed(previouslyPressedButton);
                }
            })
            .catch(() => this.setPressed(previouslyPressedButton));
    }

    /**
     * On a failed or unauthorized clear, re-presses the button the customer had just unselected — a
     * customer should never see a button go grey for a judgment that is still actually persisted.
     *
     * @param clickedButton - the (previously pressed) button that was clicked to unselect it.
     */
    protected clearJudgment(clickedButton: HTMLButtonElement): void {
        const body = new URLSearchParams({
            searchTerm: this.searchTerm,
            idProductAbstract: this.idProductAbstract,
            _csrf_token: this.csrfToken,
        });

        fetch(CLEAR_URL, {
            method: 'POST',
            body,
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((responseData: { isSuccess: boolean }) => {
                if (!responseData.isSuccess) {
                    this.setPressed(clickedButton);
                }
            })
            .catch(() => this.setPressed(clickedButton));
    }
}

<div class="titrePage">
  <h2>{'Pedra AI'|@translate} &mdash; {'Configuration'|@translate}</h2>
</div>

<form method="post" action="{$F_ACTION}">
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">

  <fieldset>
    <legend>{'API Settings'|@translate}</legend>

    <ul>
      <li>
        <label>
          {'API Key'|@translate}
          <input
            type="text"
            name="pedra_ai_api_key"
            value="{$pedra_ai_api_key|escape:'html'}"
            size="60"
            placeholder="Your Pedra AI API key"
            style="font-family:monospace"
          >
        </label>
        <span class="hint">
          {'Get your API key from'|@translate}
          <a href="https://app.pedra.ai" target="_blank" rel="noopener">app.pedra.ai</a>
          {'→ Settings → API'|@translate}
        </span>
      </li>
    </ul>
  </fieldset>

  <fieldset>
    <legend>{'Default Processing Options'|@translate}</legend>

    <ul>
      <li>
        <label>
          {'Default operation'|@translate}
          <select name="pedra_ai_default_op">
            {foreach from=$pedra_ai_operations item=op}
              <option value="{$op|escape:'html'}"{if $op == $pedra_ai_default_op} selected{/if}>
                {$op|escape:'html'}
              </option>
            {/foreach}
          </select>
        </label>
        <span class="hint">{'Operation applied by default in the Batch Manager'|@translate}</span>
      </li>

      <li>
        <label>{'Save mode'|@translate}</label>
        <br>
        <label style="display:inline-block;margin-top:4px">
          <input type="radio" name="pedra_ai_save_mode" value="new"{if $pedra_ai_save_mode == 'new'} checked{/if}>
          {'Save as new photo'|@translate}
        </label>
        &nbsp;&nbsp;
        <label style="display:inline-block">
          <input type="radio" name="pedra_ai_save_mode" value="overwrite"{if $pedra_ai_save_mode == 'overwrite'} checked{/if}>
          {'Overwrite original photo'|@translate}
        </label>
        <span class="hint" style="display:block;margin-top:4px">
          {'Overwrite permanently replaces the original file and clears all thumbnails.'|@translate}
        </span>
      </li>

      <li>
        <label>
          {'New photo suffix'|@translate}
          <input
            type="text"
            name="pedra_ai_suffix"
            value="{$pedra_ai_suffix|escape:'html'}"
            size="20"
          >
        </label>
        <span class="hint">
          {'Appended to the filename when saving as a new photo (e.g. photo_pedra.jpg)'|@translate}
        </span>
      </li>
    </ul>
  </fieldset>

  <fieldset>
    <legend>{'Available Operations'|@translate}</legend>
    <table class="table2" style="width:auto">
      <thead>
        <tr>
          <th>{'Operation'|@translate}</th>
          <th>{'Description'|@translate}</th>
        </tr>
      </thead>
      <tbody>
        <tr><td>furnish</td><td>{'Add virtual furniture to an empty room'|@translate}</td></tr>
        <tr><td>empty_room</td><td>{'Remove furniture from a room'|@translate}</td></tr>
        <tr><td>renovation</td><td>{'Simulate a renovation'|@translate}</td></tr>
        <tr><td>edit_via_prompt</td><td>{'Modify via text prompt (prompt field required)'|@translate}</td></tr>
        <tr><td>remove_object</td><td>{'Remove an unwanted object'|@translate}</td></tr>
        <tr><td>enhance</td><td>{'General photo enhancement'|@translate}</td></tr>
        <tr><td>enhance_and_correct_perspective</td><td>{'Enhancement + perspective correction'|@translate}</td></tr>
        <tr><td>sky_blue</td><td>{'Replace sky with blue sky'|@translate}</td></tr>
        <tr><td>blur</td><td>{'Background blur'|@translate}</td></tr>
      </tbody>
    </table>
  </fieldset>

  <p>
    <input type="submit" name="pedra_submit" value="{'Save Settings'|@translate}" class="submit">
  </p>
</form>

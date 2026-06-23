<div class="x_page-header">
	<h1>{{ $lang->es_search_admin_title }}</h1>
</div>

@if($XE_VALIDATOR_MESSAGE)
<div class="message {{ $XE_VALIDATOR_MESSAGE_TYPE }}">
	<p>{!! $XE_VALIDATOR_MESSAGE !!}</p>
</div>
@endif

@if($nori_installed === false)
<div class="message error">
	<p>{{ $lang->es_search_nori_not_installed }}</p>
</div>
@endif

<form action="{{ getUrl() }}" method="post" class="x_form-horizontal">
	<input type="hidden" name="module" value="admin" />
	<input type="hidden" name="act" value="procEs_searchAdminSaveConfig" />
	<input type="hidden" name="success_return_url" value="{{ getCurrentPageUrl() }}" />

	<section class="section">
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_enabled }}</label>
			<div class="x_controls">
				<label class="x_inline"><input type="radio" name="enabled" value="Y" @checked(!empty($config->enabled)) /> {{ $lang->cmd_yes }}</label>
				<label class="x_inline"><input type="radio" name="enabled" value="N" @checked(empty($config->enabled)) /> {{ $lang->cmd_no }}</label>
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_host }}</label>
			<div class="x_controls">
				<select name="es_scheme" style="width:auto">
					<option value="http" @selected($config->es_scheme === 'http')>http</option>
					<option value="https" @selected($config->es_scheme === 'https')>https</option>
				</select>
				<input type="text" name="es_host" value="{{ $config->es_host }}" placeholder="host" />
				<input type="text" name="es_port" value="{{ $config->es_port }}" placeholder="port" style="width:80px" />
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_index }}</label>
			<div class="x_controls">
				<input type="text" name="es_index" value="{{ $config->es_index }}" />
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_comment_index }}</label>
			<div class="x_controls">
				<input type="text" name="es_comment_index" value="{{ $config->es_comment_index }}" />
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_auth }}</label>
			<div class="x_controls">
				<input type="text" name="es_username" value="{{ $config->es_username }}" placeholder="{{ $lang->es_search_username }}" />
				<input type="password" name="es_password" value="" placeholder="{{ $lang->es_search_password_placeholder }}" />
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_member_only }}</label>
			<div class="x_controls">
				<label class="x_inline"><input type="radio" name="member_only_search" value="Y" @checked(!empty($config->member_only_search)) /> {{ $lang->cmd_yes }}</label>
				<label class="x_inline"><input type="radio" name="member_only_search" value="N" @checked(empty($config->member_only_search)) /> {{ $lang->cmd_no }}</label>
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_guest_message }}</label>
			<div class="x_controls">
				<input type="text" name="guest_message" value="{{ $config->guest_message }}" />
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_sync_batch_size }}</label>
			<div class="x_controls">
				<input type="text" name="sync_batch_size" value="{{ $config->sync_batch_size }}" style="width:80px" />
			</div>
		</div>
		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->es_search_sync_interval }}</label>
			<div class="x_controls">
				<input type="text" name="sync_interval_seconds" value="{{ $config->sync_interval_seconds }}" style="width:80px" />
			</div>
		</div>
	</section>

	<div class="x_clearfix btnArea">
		<div class="x_pull-right">
			<button class="x_btn x_btn-primary" type="submit">{{ $lang->cmd_submit }}</button>
		</div>
	</div>
</form>

<section class="section">
	<h2>{{ $lang->es_search_sync_status }}</h2>
	<p>{{ $lang->es_search_sync_status_documents }}: {{ sprintf($lang->es_search_pending_count, $document_pending_count) }} / {{ sprintf($lang->es_search_done_count, $document_done_count) }} / {{ sprintf($lang->es_search_failed_count, $document_failed_count) }}</p>
	<p>{{ $lang->es_search_sync_status_comments }}: {{ sprintf($lang->es_search_pending_count, $comment_pending_count) }} / {{ sprintf($lang->es_search_done_count, $comment_done_count) }} / {{ sprintf($lang->es_search_failed_count, $comment_failed_count) }}</p>

	<form action="{{ getUrl() }}" method="post" style="display:inline;">
		<input type="hidden" name="module" value="admin" />
		<input type="hidden" name="act" value="procEs_searchAdminSyncIndex" />
		<input type="hidden" name="success_return_url" value="{{ getCurrentPageUrl() }}" />
		<button class="x_btn" type="submit">{{ $lang->es_search_sync_now }}</button>
	</form>

	<form action="{{ getUrl() }}" method="post" style="display:inline;" onsubmit="return confirm('{{ $lang->es_search_confirm_flush }}');">
		<input type="hidden" name="module" value="admin" />
		<input type="hidden" name="act" value="procEs_searchAdminFlush" />
		<input type="hidden" name="success_return_url" value="{{ getCurrentPageUrl() }}" />
		<button class="x_btn x_btn-danger" type="submit">{{ $lang->es_search_flush_now }}</button>
	</form>
</section>

<section class="section">
	<h2>{{ $lang->es_search_index_fields }}</h2>

	<h3>{{ $lang->es_search_sync_status_documents }} ({{ $config->es_index }})</h3>
	<table class="x_table">
		<thead>
			<tr>
				<th>{{ $lang->es_search_field }}</th>
				<th>{{ $lang->es_search_field_desc }}</th>
			</tr>
		</thead>
		<tbody>
			<tr><td>document_srl</td><td>{{ $lang->es_search_field_document_srl }}</td></tr>
			<tr><td>module_srl</td><td>{{ $lang->es_search_field_module_srl }}</td></tr>
			<tr><td>category_srl</td><td>{{ $lang->es_search_field_category_srl }}</td></tr>
			<tr><td>member_srl</td><td>{{ $lang->es_search_field_member_srl }}</td></tr>
			<tr><td>user_id</td><td>{{ $lang->es_search_field_user_id }}</td></tr>
			<tr><td>nick_name</td><td>{{ $lang->es_search_field_nick_name }}</td></tr>
			<tr><td>title</td><td>{{ $lang->es_search_field_title }}</td></tr>
			<tr><td>content</td><td>{{ $lang->es_search_field_content_document }}</td></tr>
			<tr><td>status</td><td>{{ $lang->es_search_field_status_document }}</td></tr>
			<tr><td>regdate</td><td>{{ $lang->es_search_field_regdate }}</td></tr>
			<tr><td>list_order</td><td>{{ $lang->es_search_field_list_order }}</td></tr>
			<tr><td>update_order</td><td>{{ $lang->es_search_field_update_order }}</td></tr>
			<tr><td>readed_count</td><td>{{ $lang->es_search_field_readed_count }}</td></tr>
			<tr><td>voted_count</td><td>{{ $lang->es_search_field_voted_count }}</td></tr>
			<tr><td>comment_count</td><td>{{ $lang->es_search_field_comment_count }}</td></tr>
		</tbody>
	</table>

	<h3>{{ $lang->es_search_sync_status_comments }} ({{ $config->es_comment_index }})</h3>
	<table class="x_table">
		<thead>
			<tr>
				<th>{{ $lang->es_search_field }}</th>
				<th>{{ $lang->es_search_field_desc }}</th>
			</tr>
		</thead>
		<tbody>
			<tr><td>comment_srl</td><td>{{ $lang->es_search_field_comment_srl }}</td></tr>
			<tr><td>document_srl</td><td>{{ $lang->es_search_field_comment_document_srl }}</td></tr>
			<tr><td>module_srl</td><td>{{ $lang->es_search_field_module_srl }}</td></tr>
			<tr><td>category_srl</td><td>{{ $lang->es_search_field_comment_category_srl }}</td></tr>
			<tr><td>member_srl</td><td>{{ $lang->es_search_field_member_srl }}</td></tr>
			<tr><td>user_id</td><td>{{ $lang->es_search_field_user_id }}</td></tr>
			<tr><td>nick_name</td><td>{{ $lang->es_search_field_nick_name }}</td></tr>
			<tr><td>content</td><td>{{ $lang->es_search_field_content_comment }}</td></tr>
			<tr><td>is_secret</td><td>{{ $lang->es_search_field_is_secret }}</td></tr>
			<tr><td>status</td><td>{{ $lang->es_search_field_status_comment }}</td></tr>
			<tr><td>regdate</td><td>{{ $lang->es_search_field_regdate }}</td></tr>
			<tr><td>list_order</td><td>{{ $lang->es_search_field_list_order }}</td></tr>
		</tbody>
	</table>
</section>
